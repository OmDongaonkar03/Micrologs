<?php
// test-valkey.php

require_once "vendor/autoload.php";
require_once "../authorization/env.php";

$client = new \Predis\Client([
    "scheme" => "tcp",
    "host"   => VALKEY_HOST,
    "port"   => VALKEY_PORT,
]);

$client->set("micrologs_test", "working");
$value = $client->get("micrologs_test");

echo $value === "working" ? "Valkey connected successfully" : "Something went wrong";