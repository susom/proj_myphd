<?php


namespace Stanford\ProjMyPHD;


class InvalidInstanceException extends \Exception
{
    // My own exception
}


class FailedLockException extends \Exception
{
    // Unable to obtain a lock
}
