<?php
/** @var string $nom */
$initiales = mb_strtoupper(implode('', array_map(static fn (string $m) => mb_substr($m, 0, 1), array_slice(preg_split('/\s+/', trim($nom)) ?: [], 0, 2))));
?>
<span class="uti-initiales" aria-hidden="true"><?= e($initiales) ?></span>
