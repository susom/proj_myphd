<?php
namespace Stanford\ProjMyPHD;

use REDCap;
use Piping;
use Project;
use LogicTester;

require_once "emLoggerTrait.php";
require_once "InvalidInstanceException.php";
require_once "emLock.php";

class ProjMyPHD extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    public $instances;
    public $errors = [];

    public $project_id;
    public $record;
    public $event_id;
    public $group_id;
    public $repeat_instance;
    public $Proj;


    public function __construct() {
		parent::__construct();
	}


    /**
     * Load all non-disabled instances
     */
	public function loadSettings() {
        $this->instances = $this->getSubSettings('instance');
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

        $claimLogic   = $instance['claim-logic'];
        $extProject   = $instance['external-project'];
        $extLogic     = $instance['external-logic'];
        $extUsedField = $instance['external-used-field'];
        $extDateField = $instance['external-date-field'];
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
        $scope = implode("_", array( $this->getModuleName(), $instance['external-project'] ));
        $lock = emLock::lock($scope);
        $this->emDebug("Obtained Lock: $lock on $scope");

        // Load the current record
        $params = [
            'return_format' => 'array',
            'records' => [$this->record]
        ];
        $localRecord = REDCap::getData($params);

        // Create a project object for the external pid
        $extProj      = new Project($extProject);

        // Parse the 'special' logic for the external project
        $logic = $this->parseExternalLogic($extLogic, $localRecord);

        // Get a record from the external project
        $params = [
            "project_id" => $extProject,
            "filterLogic" => $logic,
            "return_format" => 'json'
        ];
        $q = json_decode(REDCap::getData($params), true);

        if (empty($q)) {
            $msg = "No records are available in project $extProject meeting required logic: $logic";
            $this->emDebug($msg, $params, $q);
            REDCap::logEvent($this->getModuleName() . " unable to find external record in $extProject", $msg,"",$this->record, $this->event_id);
            $this->notifyAdmin($msg);
            return false;
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

        // Outbound Mapping
        foreach ($instance['outbound-mapping'] as $o => $map) {
            $desc            = $map['outbound-desc'];
            $localField      = $map['this-field-outbound'];
            $localEventId    = $map['this-event-outbound'];
            $localInstanceId = $map['this-instance-outbound'];
            $remoteField     = $map['external-field-outbound'];

            if(empty($localField) || empty($remoteField)) {
                // Missing required input
                $this->emDebug("[$i] Missing required outbound mapping parameters");
                continue;
            }

            $localForm = $this->Proj->metadata[$localField]['form_name'];

            // Check EVENT
            if (!empty($localEventId)) {
                // EventId is hard coded - make sure it is valid for the field
                if (!in_array($localForm, $this->Proj->eventsForms[$localEventId])) {
                    throw New InvalidInstanceException("[$i] outbound: local field $localField is not enabled in local event id $localEventId - check event/form mapping");
                }
            } else {
                // Event was not specified, set local event it to current event_id
                $localEventId = $this->event_id;
                if (!in_array($localForm, $this->Proj->eventsForms[$localEventId])) {
                    throw New InvalidInstanceException("[$i] outbound: event id was blank in config so using context event id of $localEventId which does not contain the specified field $localField.  You should craft your claim logic so that this can not take place if you are unable to specify the event id up-front.  Optionally, we could just exit and not consider this a failure.");
                }
            }

            $val = $localRecord[$this->record][$localEventId][$localField];
            if (!empty($val)) {
                $extRecord[$remoteField] = $val;
                $this->emDebug("Setting $remoteField to $val");
            }

        }

        // Inbound Mapping (SUPPORTS FILES)
        foreach ($instance['inbound-mapping'] as $o => $map) {
            $desc            = $map['inbound-desc'];
            $extField        = $map['external-field-inbound'];
            $localField      = $map['this-field-inbound'];
            $localEventId    = $map['this-event-inbound'];
            $localInstanceId = $map['this-instance-inbound'];

            if(empty($localField) || empty($extField)) {
                // Missing required input
                $this->emDebug("[$i] Missing required inbound mapping parameters");
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
        REDCap::logEvent("Claimed External Record" ,$this->PREFIX . " claimed record " .
            $extRecordId . " from $extProject");

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

	public function redcap_save_record( $project_id, $record, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1) {

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
	    $this->group_id        = $group_id;
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
            } catch (\Exception $e) {
                $this->emError("Generic Exception at line " . $e->getLine() . ": " . $e->getMessage());
                REDCap::logEvent($this->getModuleName() . " Error", $e->getMessage(),"",$this->record, $this->event_id);
                $this->sendAdminEmail("<pre>" . $e->getMessage() . "</pre> with trace: <pre>" . $e->getTraceAsString() . "</pre>");
            } finally {
                // Release the lock
                emLock::release();
                $this->emDebug("Released Lock: " . emLock::$lockId);
            }

        }


	}



}
