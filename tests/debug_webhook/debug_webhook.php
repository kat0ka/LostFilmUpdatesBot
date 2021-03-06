<?php
require_once(__DIR__.'/../../ErrorHandler.php');
require_once(__DIR__.'/../../ExceptionHandler.php');

require_once(__DIR__.'/../TelegramBotMockFactory.php');
require_once(__DIR__.'/../../UpdateHandler.php');
require_once(__DIR__.'/../../webhook/Webhook.php');

require_once(__DIR__.'/input_debug_webhook.php');

$dumpFile = tempnam('/tmp', 'debug_webhook');
assert($dumpFile !== false);

$botFactory = new TelegramBotMockFactory($dumpFile);
$updateHandler = new UpdateHandler($botFactory);
$webhook = new Webhook($updateHandler);

$password = WEBHOOK_PASSWORD;
$updateJSON = $update_json;
assert(isset($updateJSON) && $updateJSON !== false);

$webhook->processUpdate($password, $updateJSON);
