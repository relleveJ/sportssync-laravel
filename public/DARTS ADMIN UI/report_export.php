<?php
// Compatibility wrapper (some pages call report_export.php while the implementation file is reportexport.php)
// This simply includes the real implementation.
require_once __DIR__ . '/reportexport.php';
