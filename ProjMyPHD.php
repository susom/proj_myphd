<?php
namespace Stanford\ProjMyPHD;

use REDCap;
use Piping;
use Project;
use LogicTester;

require_once "emLoggerTrait.php";
require_once "CustomExceptions.php";
//require_once "emLock.php";

class ProjMyPHD extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $instances;
    public $errors = [];

    public $project_id;
    public $record;
    public $event_id;
    public $repeat_instance;
    private $token_count_threshold; // When remaining tokens are less than or equal to this value, an email will be sent
    public $Proj;

    public $lock_name;

    public function __construct() {
		parent::__construct();
	}

    /**
     * TODO: xxyjl: set the default config s
     *
     * @param $version
     * @param $project_id
     * @return void
     */
    public function redcap_module_project_enable($version, $project_id) {
        //TODO: Set default values for inbound mapping
        $current = $this->getSubSettings('instance');

        if (!$current) {
            //nothihg is set so set all the defaults

            $this->emDebug("Updating first subsetting with default");
            $sub_zero_default = array(
                0 => array(
                    'external-used-field'=>'claimed_by_record',
                    'external-date-field'=>'claimed_timestamp',
                    'external-project-field'=>'claimed_by_project',
                    'external-myphd-field'=>'key_base64',
                    'inbound-myphd-field'=>'key_base64'
                )
            );
            //TODO: how to set subsettings?
            //$this->setProjectSetting('instance', $sub_zero_default);
        } else {
            $this->emDebug("No need to update");
        }

//            $this->setProjectSetting(self::PROJECT_TOKEN_KEY, $this->email_token, $project_id);

    }


    public function redcap_survey_acknowledgement_page(int $project_id, string $record = NULL, string $instrument, int $event_id, int $group_id = NULL, string $survey_hash, int $response_id = NULL, int $repeat_instance = 1) {
        $this->main($project_id, $record, $event_id, $repeat_instance);
    }


    /**
     * Upon completion of a survey we need to evaluate execution to obtain and render a MyPHD Key
     **/
    public function main($project_id, $record, $event_id, $repeat_instance = 1) {

        // Do this later
        $delay_success = $this->delayModuleExecution();
        if ($delay_success) return;

        // Load the settings
        $this->loadSettings();

        // Set context to current save event - is reused across multiple instances
        global $Proj;
        $this->project_id      = $project_id;
        $this->record          = $record;
        $this->event_id        = $event_id;
        $this->repeat_instance = $repeat_instance;
        $this->Proj            = $Proj;

        // Each instance will be run separately - a failure in one is not a failure in all
        foreach ($this->instances as $i => $instance) {

            $instance['i'] = $i;

            // // Skip invalid configurations
            // if ($this->validateClaimInstance($instance)) continue;

            try {
                // Run the claim instance
                $this->processInstance($instance);
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


    }









    /**
     * Load all non-disabled instances
     */
	public function loadSettings() {
        $this->instances = $this->getSubSettings('instance');
        $this->token_count_threshold = $this->getProjectSetting('token-count-threshold');
    }


    /**
     * Validate Claim Instance
     * @return bool
     */
	public function validateClaimInstance($instance) {
        $errors = [];

	    if (empty($instance['claim-logic'])) {
	        $errors[] = "[" . $instance['i'] . "] Missing required claim-logic";
        }
        if (empty($instance['external-project'])) {
            $errors[] = "[" . $instance['i'] . "] Missing external project";
        }

        $extProj = new Project($instance['external-project']);
        if (!empty($instance['external-used-field']) && empty($extProj->metadata[$instance['external-used-field']])) {
            $errors[] = "[" . $instance['i'] . "] External project missing external-used-field " . $instance['external-used-field'];
        }

        if (!empty($instance['external-date-field']) && empty($extProj->metadata[$instance['external-date-field']])) {
            $errors[] = "[" . $instance['i'] . "] External project missing external-date-field " . $instance['external-date-field'];
        }



        // $this->instances[$i]['_proj']
        // TODO: Other validation

        if(!empty($errors)) {
            if ($instance['disable-instance']) {
                return false;
            } else {
                return true;
            }
        } else {
            $this->errors = array_merge($this->errors, $errors);
            return false;
        }
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
        $token_count_threshold = $instance['token-count-threshold'];
        $claimLogic   = $instance['claim-logic'];
        $extProject   = $instance['external-project'];
        $extLogicRaw  = $instance['external-logic'];
        $extUsedField = $instance['external-used-field'];
        $extDateField = $instance['external-date-field'];
        $extProjectField = $instance['external-project-field'];
        $extMyPHDField    = $instance['external-myphd-field'];
        $inMyPHDField    = $instance['inbound-myphd-field'];
        $inEvent         = $instance['inbound-event'];
        $i            = $instance['i'];

        if ($instance['disable-instance']) {
            $this->emDebug("Instance $i inactive - skipping");
            return false;
        }

        // See if the logic is true to claim
        if (empty($claimLogic)) throw New InvalidInstanceException("[$i] Missing required claim-logic");
        $eval = REDCap::evaluateLogic($claimLogic,$this->project_id,$this->record,$this->event_id,$this->repeat_instance);

        if (!$eval) {
            $this->emDebug("$i: claim logic false");
            return false;
        } else {
            $this->emDebug("[$i] Claim logic true: $claimLogic");
        }

        // Obtain lock for instance - in this case, the external-project
        $this->lock_name = $this->getModuleName() . "_project_$extProject";
        $result = $this->query("SELECT GET_LOCK(?, 5)", [$this->lock_name]);
        $row = $result->fetch_row();
        if($row[0] !== 1){
            throw new FailedLockException("Unable to obtain lock on $extProject");
        }
        $this->emDebug("Obtained Lock: $this->lock_name");

        // Load the current record
        $params = [
            'return_format' => 'array',
            'records' => [$this->record]
        ];
        $localRecord = REDCap::getData($params);

        // Create a project object for the external pid
        $extProj      = new Project($extProject);

        // Parse the 'special' logic for the external project
        $extLogic = $this->parseExternalLogic($extLogicRaw, $localRecord);

        // Get a record from the external project
        $params = [
            "project_id" => $extProject,
            "filterLogic" => $extLogic,
            "return_format" => 'json'
        ];
        $q = json_decode(REDCap::getData($params), true);

        if (empty($q)) {
            $msg = "MyPHD Alert [$this->project_id]: No MyPHD Tokens available token database project $extProject meeting required logic: $extLogic";
            $this->emDebug($msg, $params, $q);
            REDCap::logEvent($this->getModuleName() . " Unable to Deliver Token", $msg,"",$this->record, $this->event_id);
            $this->notifyAdmin($msg);
            return false;
        }

        // Check if tokens remaining is below threshold
        if (!empty($token_count_threshold) && $token_count_threshold > 0) {
            if (count($q) <= $token_count_threshold) {
                $msg = "MyPHD Alert [$this->project_id]: Only " . count($q) . " tokens remain in database project $extProject - please add more tokens!";
                $this->notifyAdmin($msg);
                $this->emDebug($msg);
            }
        }

        $extRecord = array_shift($q);
        $extRecordId = $extRecord[$extProj->table_pk];
        $this->emDebug("Found external project $extProject - loaded record $extRecordId");

        // If defined, update the external used field with this record-event-instance
        if (!empty($extUsedField)) {
            // Validate the external field is in the project
            if (! isset($extProj->metadata[$extUsedField])) {
                throw New InvalidInstanceException("[$i] External project $extProject is missing extUsedField $extUsedField");
            }
            // Set value
            $extRecord[$extUsedField] = $this->record . ( $this->Proj->longitudinal ? " (" . $this->event_id . ")": '');
        }

        // Set the claim date if specified
        if (!empty($extDateField)) {
            if (! isset($extProj->metadata[$extDateField])) {
                throw New InvalidInstanceException("[$i] External project $extProject is missing extDateField $extDateField");
            }
            $extRecord[$extDateField] = date("Y-m-d H:i:s");
        }

        // Set the current project
        if (!empty($extProjectField)) {
            if (! isset($extProj->metadata[$extProjectField])) {
                throw New InvalidInstanceException("[$i] External project $extProject is missing extProjectField $extProjectField");
            }
            $extRecord[$extProjectField] = $this->project_id;
        }

        // INBOUND SETTING

        // Does $extMyPHDField exist on specified event
        $phdForm = $this->Proj->metadata[$inMyPHDField]['form_name'];

        //CHECK event for phd field
        if (!empty($inEvent)) {
            // Is field defined in event
            if (!in_array($phdForm, $this->Proj->eventsForms[$inEvent])) {
                throw New InvalidInstanceException("[$i] inbound: local event_id $inEvent does not contain field $inMyPHDField - check event/form mapping");
            }
        } else {
            // Set local event it to current event_id
            $inEvent = $this->event_id;
            if (!in_array($phdForm, $this->Proj->eventsForms[$inEvent])) {
                throw New InvalidInstanceException("[$i] inbound: local event id is not configured, using context value of $inEvent which does not contain the field $inMyPHDField.  Check form/event mappings, define the event_id in the mapping, or adjust claim logic so that a claim doesn't happen outside of the intended event_ids.");
            }
        }
        //set the local hash field
        $localRecord[$this->record][$inEvent][$inMyPHDField] = $extRecord[$extMyPHDField];

        // Inbound Mapping (SUPPORTS FILES)
        foreach ($instance['inbound-mapping'] as $o => $map) {
            $desc            = $map['inbound-desc'];
            $extField        = $map['external-field-inbound'];
            $localField      = $map['this-field-inbound'];
            $localEventId    = $map['this-event-inbound'];
            $localInstanceId = $map['this-instance-inbound'];

            if(empty($localField) || empty($extField)) {
                // Missing required input
                $this->emDebug("[$i] No inbound mapping parameters");
                continue;
            }

            // Does extField exist?
            if (!isset($extProj->metadata[$extField])) {
                throw New InvalidInstanceException("[$i] inbound mapping specifies external field $extField that does not exist in $extProject");
            }

            // Does localField exist on specified event
            $localForm = $this->Proj->metadata[$localField]['form_name'];

            // Check EVENT
            if (!empty($localEventId)) {
                // Is field defined in event
                if (!in_array($localForm, $this->Proj->eventsForms[$localEventId])) {
                    throw New InvalidInstanceException("[$i] inbound: local event_id $localEventId does not field $localField - check event/form mapping");
                }
            } else {
                // Set local event it to current event_id
                $localEventId = $this->event_id;
                if (!in_array($localForm, $this->Proj->eventsForms[$localEventId])) {
                    throw New InvalidInstanceException("[$i] inbound: local event id is not configured, using context value of $localEventId which does not contain the field $localField.  Check form/event mappings, define the event_id in the mapping, or adjust claim logic so that a claim doesn't happen outside of the intended event_ids.");
                }
            }

            // Is there any value in the extProject - if not, skip it.
            if (empty($extRecord[$extField])) continue;

            // Determine type of field in extProject
            $extElementType = $extProj->metadata[$extField]['element_type'];

            if ($extElementType == "file") {

                if($this->Proj->metadata[$localField]['element_type'] !== 'file') {
                    // local filetype isn't file
                    throw New InvalidInstanceException("[$i] Inbound field $localField must be a file type to match external field $extField in project $extProject");
                }

                // We have a file - let's copy it
                $edocId = $extRecord[$extField];
                $newEdocId = copyFile($edocId, $this->project_id);

                $localRecord[$this->record][$localEventId][$localField] = $newEdocId;
                $this->emDebug("$localField is a file - copied $edocId to $newEdocId");
            } else {
                // Just copy the other data as-is
                $localRecord[$this->record][$localEventId][$localField] = $extRecord[$extField];
            }
        }

        // Update External Project
        $q = REDCap::saveData($extProject, 'json', json_encode(array($extRecord)));
        if (!empty($q['errors'])) {
            throw new InvalidInstanceException("Errors during save to claim project $extProject:\n" .
                json_encode($q['errors']) . "\nwith:\n" .  json_encode($extRecord));
        }

        // Save to current project -- had to use record method to include file id...
        // TODO: If the records object changes, this part is fragile and could be the source of future failures
        $params = [
            0 => $this->project_id,
            1 => 'array',
            2 => $localRecord,
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
            throw New InvalidInstanceException("Errors during local save of record $this->record : " . json_encode($q['errors']));
        }

        $this->emDebug("Claimed record $extRecordId from project $extProject");
        REDCap::logEvent("Claimed External MyPHD Key record " . $extRecordId . " from $extProject");
        $foo = $extRecord[$extMyPHDField];

        // Initialize Javascript
        $this->initializeJavascriptModuleObject()

        ?>
            <script>
                $(function() {
                    const module = <?=$this->getJavascriptModuleObjectName()?>;

                    module.token = <?php echo $extRecord[$extMyPHDField] ?>;

                    try {
//xxyjl                        window.webkit.messageHandlers.nativeProcessnative.postMessage(module.token);
                        console.log("webkit token sent" +module.token);
                    } catch(err) {
                        console.log(err.message):
                    }

                    // For Android
                    try {
                        (function callAndroid() {
                            var token = <?php echo $extRecord[$extMyPHDField] ?>;
//xxyjl                            document.location = "js://webview?status=0&myphdkey=" + encodeURIComponent(token);
                        })();
                        console.log("Android success");
                    } catch (err) {
                        console.log("Android Error", err.message)
                    }

                })
            </script>
        <?php

        // Done
        return true;
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


    public function dumpResource($name)
    {
        $file = $this->getModulePath() . $name;
        if (file_exists($file)) {
            $contents = file_get_contents($file);
            return $contents;
        } else {
            $this->emError("Unable to find $file");
        }
    }



}
