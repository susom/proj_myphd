# ProjMyPHD

## Authors
Stanford University, Andrew Martin and Jae Lee

## Purpose
ProjMyPHD is a project-specific EM used to grab the 'next' matching key from an external key project and return values back to the main project.  It is similar to how REDCap's allocation-based randomization works.
This EM was motivated by multiple projects:
- A giftcard project that uses similar logic to pull the next available, unused electronic giftcard when someone completes some surveys
- A secure mobile app project where eligible participants were emailed a binary activation key to link a mobile app to their research profile.  This EM grabs the next key from a external project full of keys and then issues the key to a participant via an alert.
- Better blinded randomization is also a primary motivator.  Currently, researchers and participants know if they are on 'Drug A' or 'Drug B' after randomization.  With this module, each participant gets a random phrase instead of Drug A and Drug B that is linked to their actual cohort.  Only the pharmacist need access to the lookup database to translate the unique cohort name into the correct drug -- keeping the main study team fully blinded.  This is probably the best use thus far for the EM.

## Install Instructions
1. Create a MyPhd Key repository project. We have provided a sample project xml here: https://github.com/susom/proj_myphd/tree/main/assets
2. Install this Proj MyPHD EM in your main project
3. Configure EM

## Example EM Configuration Settings


  | Config                             | Example Input                               | Note                                                                                                |
|------------------------------------|---------------------------------------------|-----------------------------------------------------------------------------------------------------|
  | Survey Form                        | form_1                                      | name of form that trigges                                                                           |
  | Attempt Claim Logic                | [form_1_complete]<> '0' AND [myphd_id] = '' |                                                                                                     |
  | MyPHD Key Database Project         | 12345                                       | project ID of the project repository                                                                |
  | MyPHD key field                    | myphd_id                                    | Field in main project where you want to store the consumed key                                      |
  | Event in this project              | arm_1                                       | Event in which above field (myphd_id) resides                                                       |
  | External Claimed By Record Field   | claimed_by_record                           | Field in repository project to store record id. If you used the provided xml, use this field        |
  | External Lookup Logic              | {claimed_by_record} = ""                    | This logic will only used unclaimed keys                                                            |
  | External Timestamp Claimed Field   | claimed_timestamp                           | Field in repository project to store timestamp. If you used the provided xml, use this field        |
  | External Project Field             | cliamed_by_project                          | Field in repository project to store claiming project. If you used the provided xml, use this field |
  | MyPHD Key Field                    | key_base64                                  | Field in repository project for key. If you used the provided xml, use this field                   |
  | Token Count Threshold              | 25                                          | If count of available tokens fall below 25, the admin entered below will be notified                |
  | Error Email                        | admin@bar.com                               ||
  | OPTIONAL FIELDS |||
  | Field name in External KEY project | claimed_timestamp                           | Field you want to migrate over                                                                      |
  | Field name in this project         | myphd_token_timestamp | Field in this project to poppulate |
  | Event in this project    | Event in which field above is located ||

## What's Fancy
This module supports two fancy features:

### Double Piping
* the logic to select the next record from the external project is a mix of normal REDCap logic (supporting piping and smart-variables in the main project) with a twist to convert this into logic for the external project.  For example:
    ```
    {used_by} = '' and {site} = '[record-dag-name]'
    ```
  is a valid configuration.  Before filtering on the external project, this expression will do normal piping which will replace the smart variable record-dag-name with the current record's dag in the main project.  Then, the `{field}` will be converted to `[field]` and used to query the external project.  So, the actual external query looks like:
    ```
    [used_by] = '' and [site] = 'daggroupa'
    ```

### emLock
* A second interesting feature is driven by the need to ensure that claiming of codes is atomic.  We have had cases where under high utilization two different php sessions might try to claim the same, next available record.

With the latest enhancements, you can now incorporate smart-variables like DAG name into the lookup logic.

Good luck - and reach out to the consortium if you are having setup difficulties.
