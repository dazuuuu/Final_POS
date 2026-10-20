<?php
// public/terminal/index.php — the shared staff PIN terminal (domain/terminal/).
// The plain domain now shows the landing page; staff on the shared address sign
// in here. A shop's own address (domain/<slug>/) still opens its terminal directly.
define('DAZU_TERMINAL', true);
require __DIR__ . '/../index.php';
