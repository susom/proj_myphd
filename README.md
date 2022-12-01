# ProjMyPHD

## Authors
Stanford University, Andrew Martin and Jae Lee

## Purpose
ProjMyPHD is a project-specific EM used to grab the 'next' matching key from an external key project and return values back to the main project.  It is similar to how REDCap's allocation-based randomization works.
This EM was motivated by multiple projects:
- A giftcard project that uses similar logic to pull the next available, unused electronic giftcard when someone completes some surveys
- A secure mobile app project where eligible participants were emailed a binary activation key to link a mobile app to their research profile.  This EM grabs the next key from a external project full of keys and then issues the key to a participant via an alert.
- Better blinded randomization is also a primary motivator.  Currently, researchers and participants know if they are on 'Drug A' or 'Drug B' after randomization.  With this module, each participant gets a random phrase instead of Drug A and Drug B that is linked to their actual cohort.  Only the pharmacist need access to the lookup database to translate the unique cohort name into the correct drug -- keeping the main study team fully blinded.  This is probably the best use thus far for the EM.

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

