<?php
/** @var array{volunteer:string, endeavour:string, entity:string, schedule:string} $context */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Registration</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#1f2937">
    <h2 style="color:#0d6efd">Nixor Endeavours &mdash; Parent Notice</h2>
    <p>Dear Parent/Guardian,</p>
    <p><strong><?= htmlspecialchars($context['volunteer'], ENT_QUOTES) ?></strong> has registered interest in the endeavour <strong><?= htmlspecialchars($context['endeavour'], ENT_QUOTES) ?></strong> hosted by <strong><?= htmlspecialchars($context['entity'], ENT_QUOTES) ?></strong>.</p>
    <p>Schedule: <?= htmlspecialchars($context['schedule'], ENT_QUOTES) ?></p>
    <p>This email was sent automatically. Please contact the entity manager if you have any questions.</p>
    <p style="margin-top:2rem;color:#6b7280;font-size:0.85rem;">&copy; <?= date('Y') ?> Nixor College</p>
</body>
</html>
