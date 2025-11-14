<?php
/** @var array{volunteer:string, endeavour:string, deadline:string} $context */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Consent Reminder</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#1f2937">
    <h2 style="color:#0d6efd">Consent Pending</h2>
    <p>Dear <?= htmlspecialchars($context['volunteer'], ENT_QUOTES) ?>,</p>
    <p>This is a friendly reminder to complete the consent form for <strong><?= htmlspecialchars($context['endeavour'], ENT_QUOTES) ?></strong>.</p>
    <p>Please submit the form by <?= htmlspecialchars($context['deadline'], ENT_QUOTES) ?>.</p>
    <p>Thank you for your commitment to the Nixor community.</p>
</body>
</html>
