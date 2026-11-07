<?php
// Existing suites explicitly exercise the retained unpaid/real-provider Phase 2/3 branches.
if(!str_ends_with($config['db_name'],'_test'))exit("Legacy fixtures require a separate _test database.\n");
db()->exec('UPDATE business_settings SET demo_payments_enabled=0 WHERE id=1');
