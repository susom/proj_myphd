# ProjMyPHD

## Authors
Stanford University, Andrew Martin

## Purpose
ProjMyPHD is a project-specific EM used to grab the 'next' matching record from an external project and return values back to the main project.  It is similar to how REDCap's allocation-based randomization works.
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
### emLock
* A second interesting feature is driven by the need to ensure that claiming of codes is atomic.  We have had cases where under high utilization two different php sessions might try to claim the same, next available record.  To prevent this from happening, a new class and two new database tables were created.  The class is called emLock and uses the mysql database to do a row-level lock based on a scope defined in the code that should be unqiue to the resource being reserved.  This is all behind the scenes but means that the redcap user must have rights to creaete tables or else these statements must be run

With the latest enhancements, you can now incorporate smart-variables like DAG name into the lookup logic.

Good luck - and reach out to the consortium if you are having setup difficulties.

#### Here is the SQL for the emLock Tables:
```sql
create table redcap_em_lock
(
    id int NOT NULL AUTO_INCREMENT,
    scope varchar(256) UNIQUE NOT NULL,
    creation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);

create table redcap_em_lock_log
(
    id int NOT NULL AUTO_INCREMENT,
    lock_id int NOT NULL,
    creation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    duration_ms int,
    PRIMARY KEY (id)
);

alter table redcap_em_lock_log
add constraint redcap_em_lock_log_redcap_em_lock_id_fk
    foreign key (lock_id) references redcap_em_lock (id)
        on delete cascade;
```
