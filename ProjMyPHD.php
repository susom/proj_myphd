<?php
namespace Stanford\ProjMyPHD;

use Couchbase\InvalidConfigurationException;
use REDCap;
use Piping;
use Project;
use LogicTester;

require_once "emLoggerTrait.php";
require_once "CustomExceptions.php";

class ProjMyPHD extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $instances;
    public $errors = [];

    // public $project_id;
    // public $record;
    // public $event_id;
    // public $repeat_instance;
    public $Proj;

    private $claim_form;
    private $claim_logic;
    private $ext_project;
    private $inbound_key_field;
    private $inbound_event;
    private $ext_used_field;
    private $ext_logic_raw;
    private $ext_timestamp_field;
    private $ext_project_field;
    private $ext_key_field;
    private $token_count_threshold; // When remaining tokens are less than or equal to this value, an email will be sent

    public $lock_name;

    public function __construct() {
		parent::__construct();
	}


    /**
     * Set a default project setting
     * setting force to true will replace existing values
     * @param $setting
     * @param $value
     * @param $project_id
     * @param $force
     * @return void
     */
    private function setDefaultSetting($setting, $value, $project_id, bool $force = false) {
        $current = $this->getProjectSetting($setting, $project_id);
        if (empty($current) || $force) {
            $this->setProjectSetting($setting, $value, $project_id);
        }
    }


    /**
     * Set Default Values on Enable for a Project
     * @param $version
     * @param $project_id
     * @return void
     */
    public function redcap_module_project_enable($version, $project_id) {
        // TODO: A more generic way to do this would be to read the config.json and just scan it for default values
        $this->setDefaultSetting('external-used-field', 'claimed_by_record', $project_id);
        $this->setDefaultSetting('external-logic', '{claimed_by_record} = ""', $project_id);
        $this->setDefaultSetting('external-timestamp-field', 'claimed_timestamp', $project_id);
        $this->setDefaultSetting('external-project-field', 'claimed_by_project', $project_id);
        $this->setDefaultSetting('external-key-field', 'key_base64', $project_id);
        $this->setDefaultSetting('token-count-threshold', '25', $project_id);
    }


    public function redcap_survey_acknowledgement_page(int $project_id, string $record, string $instrument, int $event_id, int $group_id = NULL, string $survey_hash, int $response_id = NULL, int $repeat_instance = 1) {
        $this->main($project_id, $record, $instrument, $event_id, $repeat_instance);
    }


    private function settingsValid() {
        // TODO: Check that settings are valid
        $this->loadSettings();
        $this->errors = [];
        if (empty($this->claim_logic)) $errors[] = "Missing required claim-logic in module config";
        return empty($this->errors);
    }

    /**
     * Upon completion of a survey we need to evaluate execution to obtain and render a MyPHD Key
     **/
    public function main($project_id, $record, $instrument, $event_id, $repeat_instance = 1)
    {

        // Delay execution (not sure why)
        $delay_success = $this->delayModuleExecution();
        if ($delay_success) return false;

        // Only trigger if claim form is set and matches
        $this->claim_form = $this->getProjectSetting('claim-form');
        if ($instrument !== $this->claim_form) return false;

        if (!$this->settingsValid()) {
            $this->emDebug("Invalid Settings");
            // TODO: Email admin
            return false;
        }

        try {
            // See if the logic is true to claim
            $eval = REDCap::evaluateLogic($this->claim_logic,$project_id,$record,$event_id,$repeat_instance);
            if (!$eval) {
                $this->emDebug("MyPHD claim logic false");
                return false;
            } else {
                $this->emDebug("MyPHD  Claim logic true");
            }

            // Load the current record
            $local_record_data = REDCap::getData(
                [
                    'return_format' => 'array',
                    'records' => [$record]
                ]
            );

            // Create a project object for the external pid
            global $Proj;
            $extProj      = new Project($this->ext_project);

            // Obtain lock for instance - in this case, the external-project
            $this->lock_name = $this->getModuleName() . "_project_" . $this->ext_project;
            $result = $this->query("SELECT GET_LOCK(?, 5)", [$this->lock_name]);
            $row = $result->fetch_row();
            if($row[0] !== 1){
                throw new FailedLockException("Unable to obtain lock on " . $this->ext_project);
            }
            $this->emDebug("Obtained Lock: $this->lock_name");

            // Parse the 'special' logic for the external project
            $ext_logic = $this->parseExternalLogic($this->ext_logic_raw, $local_record_data);

            // Get a record from the external project
            $params = [
                "project_id" => $this->ext_project,
                "filterLogic" => $ext_logic,
                "return_format" => 'json'
            ];
            $q = json_decode(REDCap::getData($params), true);
            if (empty($q)) {
                $msg = "MyPHD Alert [$project_id]: No MyPHD Tokens available token database project " .
                    $this->ext_project . " meeting required logic: $ext_logic";
                $this->emDebug($msg, $params, $q);
                REDCap::logEvent($this->getModuleName() . " Unable to Deliver Token", $msg,"",$record, $event_id);
                $this->notifyAdmin($msg);
                return false;
            }

            // Check if tokens remaining is below threshold
            if (!empty($token_count_threshold) && $token_count_threshold > 0) {
                if (count($q) <= $token_count_threshold) {
                    $msg = "MyPHD Alert [$project_id]: Only " . count($q) . " tokens remain in database project " .
                        $this->ext_project . " - please add more tokens!";
                    $this->notifyAdmin($msg);
                    $this->emDebug($msg);
                }
            }

            // Take the first available record
            $ext_record = array_shift($q);
            $ext_record_id = $ext_record[$extProj->table_pk];
            $this->emDebug("Found external project $this->ext_project - loaded record $ext_record_id");

            // If defined, update the external used field with this record-event-instance
            if (!empty($this->ext_used_field)) {
                // Validate the external field is in the project
                if (! isset($extProj->metadata[$this->ext_used_field])) {
                    throw New InvalidConfigurationException("External project $this->ext_project is missing "
                    . " the required external-used-field configuration " . $this->ext_used_field);
                }
                // Set value (appending event_id if necessary)
                $event_info = $Proj->longitudinal ? " (" . REDCap::getEventNames(false,false,$event_id) . ")" : '';
                $ext_record[$this->ext_used_field] = $record . $event_info;
            }

            // Set the claim date if specified
            if (!empty($this->ext_timestamp_field)) {
                // Validate the external field is in the project
                if (! isset($extProj->metadata[$this->ext_timestamp_field])) {
                    throw New InvalidConfigurationException("External project $this->ext_project is missing "
                        . " the required external-timestamp-field " . $this->ext_timestamp_field);
                }
                $ext_record[$this->ext_timestamp_field] = date("Y-m-d H:i:s");
            }

            // Set the current project
            if (!empty($this->ext_project_field)) {
                if (! isset($extProj->metadata[$this->ext_project_field])) {
                    throw New InvalidConfigurationException("External project $this->ext_project is missing "
                    . " the required external-project-field " . $this->ext_project_field);
                }
                $ext_record[$this->ext_project_field] = $project_id;
            }

            // INBOUND SETTING

            // Does $extMyPHDField exist on specified event
            $inbound_form = $Proj->metadata[$this->inbound_key_field]['form_name'];

            // CHECK event for inbound key field
            if (empty($this->inbound_event)) $this->inbound_event = $event_id;

            // Is field defined in event?
            if (!in_array($inbound_form, $Proj->eventsForms[$this->inbound_event])) {
                throw New InvalidConfigurationException("Inbound: local inbound-event " .
                        $this->inbound_event . " does not contain inbound-key-field " . $this->inbound_key_field .
                        " - check event/form mapping or your configuration");
            }

            // Copy the key from the external record to this record
            $update_local_data[$record][$this->inbound_event][$this->inbound_key_field] = $ext_record[$this->ext_key_field];

            // Inbound Mapping (SUPPORTS FILES)
            $inbound_mapping = $this->getSubSettings('inbound-mapping');
            foreach ($inbound_mapping as $map) {
                $desc                   = $map['inbound-desc'];
                $ext_field_inbound      = $map['external-field-inbound'];
                $local_field_inbound    = $map['this-field-inbound'];
                $local_event_inbound    = $map['this-event-inbound'];
                $local_instance_inbound = $map['this-instance-inbound'];

                if(empty($local_field_inbound) || empty($ext_field_inbound)) {
                    // Empty inbound instance
                    $this->emDebug("No inbound mapping parameters: ", $map);
                    continue;
                }

                // Does external_field exist?
                if (!isset($extProj->metadata[$ext_field_inbound])) {
                    throw New InvalidConfigurationException("inbound mapping specifies external field " .
                        "$ext_field_inbound that does not exist in $this->ext_project");
                }

                // Does localField exist on specified event
                $local_form = $Proj->metadata[$local_field_inbound]['form_name'];

                // Is field defined in event
                if (!in_array($local_form, $Proj->eventsForms[$local_event_inbound])) {
                        throw New InvalidConfigurationException("inbound: local event_id " .
                            $local_event_inbound . " does not contain field $local_field_inbound - check event/form mapping");
                }

                // Is there any value in the extProject - if not, skip it.
                if (empty($ext_record[$ext_field_inbound])) continue;

                // Determine type of field in extProject
                $ext_element_type = $extProj->metadata[$ext_field_inbound]['element_type'];

                // Set the value for the inbound field (copying file if necessary)
                if ($ext_element_type == "file") {
                    if($Proj->metadata[$local_field_inbound]['element_type'] !== 'file') {
                        // local filetype isn't file
                        throw New InvalidConfigurationException("Inbound field $local_field_inbound must be a " .
                            "file type to match external field $ext_field_inbound in project $this->ext_project");
                    }

                    // We have a file - let's copy it
                    $edocId = $ext_record[$ext_field_inbound];
                    $value = REDCap::copyFile($edocId, $project_id);
                    $this->emDebug("$local_field_inbound is a file - copied $edocId to $value");
                } else {
                    // Just copy the data from ext project to here as-is without type checking
                    $value = $ext_record[$ext_field_inbound];
                }
                $update_local_data[$record][$local_event_inbound][$local_field_inbound] = $value;
            }

            // Update External Project
            $q = REDCap::saveData($this->ext_project, 'json', json_encode(array($ext_record)));
            if (!empty($q['errors'])) {
                throw new InvalidInstanceException("Errors during save to claim project $this->ext_project:\n" .
                    json_encode($q['errors']) . "\nwith:\n" .  json_encode($ext_record));
            }

            // Save to current project -- had to use record method to include file id...
            // TODO: If the records object changes, this part is fragile and could be the source of future failures
            $params = [
                0 => $project_id,
                1 => 'array',
                2 => $update_local_data,
                3 => 'normal',
                4 => 'YMD',
                5 => 'flat',
                6 => null,
                7 => true,
                8 => true,
                9 => true,
                10 => false,
                11 => true,
                12 => [],
                13 => false,
                14 => false // THIS IS WHAT WE NEED TO OVERRIDE FOR FILES TO BE 'SAVABLE'
            ];
            $q = call_user_func_array(array("\Records", "saveData"), $params);

            if (!empty($q['errors'])) {
                throw New InvalidInstanceException("Errors during local save of record $record : " . json_encode($q['errors']));
            }

            $this->emDebug("Claimed record $ext_record_id from project $this->ext_project");
            REDCap::logEvent("Claimed External MyPHD Key record " . $ext_record_id . " from $this->ext_project");

            $this->renderKey($ext_record[$this->ext_key_field]);
        } catch (InvalidInstanceException $e) {
            $this->emError("Caught InvalidInstanceException at line " . $e->getLine() . ": " . $e->getMessage());
            REDCap::logEvent($this->getModuleName() . " Alert", $e->getMessage(),"",$this->record, $this->event_id);
        } catch (FailedLockException $e) {
            $this->emError("Unable to obtain a unique lock on key database at " . $e->getLine() . ": " . $e->getMessage());
            REDCap::logEvent($this->getModuleName() . " Alert", $e->getMessage(),"",$this->record, $this->event_id);
        } catch (\Exception $e) {
            $this->emError("Generic Exception at line " . $e->getLine() . ": " . $e->getMessage());
            REDCap::logEvent($this->getModuleName() . " Error", $e->getMessage(),"",$this->record, $this->event_id);
            $this->sendAdminEmail("<pre>" . $e->getMessage() . "</pre> with trace: <pre>" . $e->getTraceAsString() . "</pre>");
        } finally {
            // Release the lock
            if ($this->lock_name != null) {
                $this->query("SELECT RELEASE_LOCK(?)", [$this->lock_name]);
                $this->emDebug("Released Lock: " . $this->lock_name);
            }
        }
    }


    private function renderKey($key_string) {

        // Initialize Javascript for rendering
        $this->initializeJavascriptModuleObject();
        ?>
            <script>
                $(function() {
                    const module = <?= $this->getJavascriptModuleObjectName() ?>;
                    module.myphdkey = <?= json_encode($key_string) ?>;

                    try {
                        window.webkit.messageHandlers.nativeProcessnative.postMessage(module.myphdkey);
                        console.log("webkit token sent!");
                    } catch(err) {
                        console.log("Error sending webkit message");
                        // console.log(err.message):
                        window.lastError = err;
                    }

                    // For Android
                    try {
                        (function callAndroid() {
                            const myphdkey = <?= json_encode($key_string) ?>;
                            document.location = "js://webview?status=0&myphdkey=" + encodeURIComponent(myphdkey);
                        })();
                        console.log("Android success");
                    } catch (err) {
                        console.log("Android Error")
                        window.lastError = err;
                    }
                })
            </script>
        <?php
    }


    /**
     * Load all non-disabled instances
     */
	public function loadSettings() {
        // $this->claim_form           = $this->getProjectSetting(['claim-form']);
        $this->claim_logic           = $this->getProjectSetting('claim-logic');
        $this->ext_project           = $this->getProjectSetting('external-project');
        $this->inbound_key_field     = $this->getProjectSetting('inbound-key-field');
        $this->inbound_event         = $this->getProjectSetting('inbound-event');
        $this->ext_used_field        = $this->getProjectSetting('external-used-field');
        $this->ext_logic_raw         = $this->getProjectSetting('external-logic');
        $this->ext_timestamp_field   = $this->getProjectSetting('external-timestamp-field');
        $this->ext_project_field     = $this->getProjectSetting('external-project-field');
        $this->ext_key_field         = $this->getProjectSetting('external-key-field');
        $this->token_count_threshold = $this->getProjectSetting('token-count-threshold');
    }


    /**
     * Returns true or false
     * @param $instance
     * @return bool
     * @throws \Exception
     */
    public function processInstance($instance) {
        /*
            Array
            (
                [0] => Array
                    (
                        [claim-logic] => [random_group_1] <> ''
                        [external-project] => 16
                        [external-logic] => {used_by} = ''
                        [external-used-field] => used_by
                        [external-date-field] => used_date
                        [outbound-mapping] => Array
                            (
                                [0] => Array
                                    (
                                        [this-field-outbound] => sex
                                        [this-event-outbound] =>
                                        [this-instance-outbound] =>
                                        [external-field-outbound] => inbound_1
                                    )
                            )
                        [inbound-mapping] => Array
                            (
                                [0] => Array
                                    (
                                        [external-field-inbound] => code
                                        [this-field-inbound] => random_alias
                                        [this-event-inbound] =>
                                        [this-instance-inbound] =>
                                    )

                                [1] => Array
                                    (
                                        [external-field-inbound] => record_id
                                        [this-field-inbound] => random_id
                                        [this-event-inbound] => 44
                                        [this-instance-inbound] =>
                                    )
                            )
                        [disable-instance] =>
                    )
            )
         */

	}


    /**
     * This module does a double-parsing to allow a mix of local smartvars into the query that is applied on the
     * external project.
     * i.e.  {claimed_date}='' and {dag} = '[record-dag-name]'
     * would first be piped into:
     * {claimed_date}='' and {dag} = 'dag-group-a'
     * and then changed into:
     * [claimed_date]='' and [dag] = 'dag-group-a'
     * @param $logic
     * @return string|string[]|null
     */
	private function parseExternalLogic($logic, $record_data) {
        $this->emDebug("Starting with logic: $logic");

        // Pipe any special piping tags in THIS project
        $user = defined("USERID") && !empty(USERID) ? USERID : NULL;

        if ($this->Proj->longitudinal) {
            $logic = LogicTester::logicPrependEventName($logic, $this->Proj->getUniqueEventNames($this->event_id), $this->Proj);
            // $this->emDebug("Prepending event names: $logic");
        }

        $logic = Piping::replaceVariablesInLabel($logic, $this->record, $this->event_id, $this->repeat_instance,
            $record_data, false, $this->project_id, false);
        // $this->emDebug("Post-replaceVariablesInLabel logic: $logic");

        // $logic = Piping::pipeSpecialTags($logic, $this->project_id, $this->record, $this->event_id, $this->repeat_instance, $user, true);
        // $this->emDebug("Post-piping logic: $logic");

        // Convert {} to [] for destination project
        $logic = preg_replace( [ '/\{/', '/\}/' ], [ '[', ']' ], $logic);
        $this->emDebug("Post-convert logic: $logic");
        return $logic;
    }

    /**
     * Notify an administrator when the lookup project is not returning results
     * @param $msg
     */
    public function notifyAdmin($msg) {
	    $email = $this->getProjectSetting('error-email-address');
	    if (!empty($email)) {
	        global $project_contact_email;
	        $subject = "REDCap EM Notification for " . $this->getModuleName() . " in project " . $this->project_id;
            REDCap::email($email,$project_contact_email,$subject,$msg);
        }
    }

}
