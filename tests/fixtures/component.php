<?php

declare(strict_types=1);

// Stand-in for a component: reads its data dir and exits 0.
exit(is_dir((string) getenv('KBC_DATADIR')) ? 0 : 1);
