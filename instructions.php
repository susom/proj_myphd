<?php
namespace Stanford\ProjMyPHD;
/** @var ProjMyPHD $module */


?>
<h4><?php echo $module->getModuleName() ?> Instructions</h4>

<p>
    This is a project specific External Module for MyPhD.  MyPHD is a personal health dashboard powered by researcher at Stanford.  More information can be found here: https://myphd.stanford.edu/.  This module enabled the distribution of base64 secure tokens as part of the application signup process.
</p>

<dl>
    <dt>Claim Logic</dt>
    <dd>This logic is evaluated each time a record is saved in this project.  If the logic is true, the
        instance of claim will be evaluted</dd>
    <dt>External Project</dt>
    <dd>This EM works by pulling a record from another REDCap project.  The external project should be thought of as
    a database of tokens or some other commodity that is being claimed by the record in this project.  Think of
    waiting in line at the deli and pulling the next number from the spool.  <strong>This project must be classical</strong></dd>
    <dt>External Logic</dt>
    <dd>When a claim is triggered, the EM tries to find a record in the external project based on the External Logic.
    This logic is <strong>special</strong> and unique to this module.  Please look at the examples carefully to
    make sure you're using this field properly.  It uses { } brackets for the external project instead of [ ] brackets.
    This allows you to do some powerful queries using smart variables from this project and the external project.</dd>
    <dt>Inbound Mapping Rules</dt>
    <dd>When a claim is made, data from the external project can be copied into this project.  Examples might include
    gift card tokens or even files that contain keys or images</dd>
    <dt>Outbound Mapping Rules</dt>
    <dd>
        When a claim is made, you can also push data from the current project into the external project.
    </dd>
</dl>

<p>
    Monitor your project logs for errors or notifications.  Additionally, you can configure the EM to email you
    when something wrong happens.
</p>
<hr>
<h5>Example 1: Double-blinded Randomization</h5>
<p>
    When a record is randomized, we want to assign a 'code word' to a participant.  This word can only be decoded
    by the pharmacist to determine whether they get Drug A or Placebo B.  We begin by building our 'EXTERNAL' project
    - a pharmacy codebook that contains fields like:
</p>
    <table class="form_border container-fluid">
        <tr>
            <th>record_id</th><th>random_group</th><th>coded_value</th><th>used_by</th>
        </tr>
        <tr>
            <td>1</td><td>A</td><td>Agile Ardvark</td><td></td>
        </tr>
        <tr>
            <td>2</td><td>A</td><td>Beta Beetle</td><td></td>
        </tr>
        <tr>
            <td>3</td><td>B</td><td>Yellow YoYo</td><td></td>
        </tr>
    </table>
<p>
    In our main project we have all the normal variables plus two that are important for this module.  First, the
    field where we store the result of a normal REDCap randomization: <code>drug_arm</code>.  Next, we need a place
    to put the coded value we will pull from the external project - we call that <code>pharam_code</code>.
</p>
<p>
    In configuring the module, we first set the <strong>Claim Logic</strong>.  We don't want to start until randomization is done
    (e.g. <code>[drug_arm] <> ''</code>) but we don't want to re-run the claim logic every time we save after that so
    we also make sure the pharam code is empty.  It should get set if this EM works.
</p>
<div><code>[drug_arm] <> '' AND [pharma_code] = ''</code></div>
<p>
    Next, we set the project id of our External Database and define the <strong>External Lookup Logic</strong>.
    We want to find the first pharma database record that is not used and matches our random group.
    That is, we want the pharma db's <code>random_group</code> value to match the <code>drug_arm</code> from
    the main project.  The logic looks like this:
</p>
<div><code>{used_by} = '' AND {random_group} = '[drug_arm]'</code></div>
<p>
    The squiggly brackets are for the external project and the square brackets are for the main project.  This allows
    you to use piping and smart variables from the main project, such as [record-dag-name] to match against the external
    project.  <strong>Pay attention to quotes here</strong> - if you're piping in a variable you have to put it in quotes so the
    final logical expression is syntactically valid.  Check the project's logging for error messages.
</p>
<p>
    Next you define the mapping of fields from the current project to the external and visa-versa.  Keep in mind the
    external project must be classical.  <i>While the UI supports repeating instances on the local project, that hasn't been tested and probably doesn't yet work.</i>
</p>
<p>
    ...
</p>
