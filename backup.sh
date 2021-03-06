#!/bin/bash
cd "`dirname $0`"

# Several times a day
mysqldump cnctu |gzip > backup/database/database-`date +%Y%m%d-%H%M`.sql.gz

# Monthly
tar czf backup/img/img-`date +%Y%m`.tgz img
