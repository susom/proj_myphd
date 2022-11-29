<?php
namespace Stanford\ProjMyPHD;
/** @var ProjMyPHD $module */


?>
<h4><?php echo $module->getModuleName() ?> Instructions</h4>

<p>
    This is a project specific External Module for MyPhD.  MyPHD is a personal health dashboard powered by researcher
    at Stanford.  More information about the project can be found here: https://myphd.stanford.edu/.
</p>

<dl>
    <dt>What does this module do?</dt>
    <dd>This module should be enabled on each consent project for new myPhd studies.  When an app user is redirected
        to complete the consent in this project, the module will obtain and transfer an encryption key from a separate
        REDCap Key Database to the client so future communication can be encrypted.</dd>
    <dt>How do I set this project up?</dt>
    <dd>You must create or use an existing MyPHD Key Database.  You can download the XML template to create a REDCap
        a new key database project here:
        <a href="<?=$module->getUrl("assets/MyPHDKeyDatabase_v1.REDCap.xml")?>" target="_blank">MyPHDKeyDatabase_v1.REDCap.xml</a>.
        <br>
        This module assumes you are using the correct template project otherwise you may need to update the configuration
        details.  Additionally, once you create the Key Database project, you must upload a series of keys, most importantly
        including the base64 hash of the key.  Instructions for this step reside with a different repository.
    </dd>
    <dt>How does this work?</dt>
    <dd>Once the consent is completed and logical criteria defined in the config are true, this module will pull the next
        available key from the Key Database and render it to the client.  It then marks the key as used.
    </dd>
    <dt>Can the key database be shared by multiple myPHD projects?</dt>
    <dd>Yes - one key database can be used by multiple MyPHD projects that have this EM enabled and configured.  The
        module applies a lock when obtaining a key that ensures that each key can be used only once.
    </dd>
    <dt>Inbound Mapping Rules</dt>
    <dd>When a claim for a key is made, additional data from the key database project can be copied into this project.
        Examples might include gift card tokens or even files that contain keys or images</dd>
</dl>

