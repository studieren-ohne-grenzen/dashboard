#!/bin/bash

# let's get the directory right
SCRIPT=$(readlink "$0")
DIR=$(dirname "$SCRIPT")

cd "$DIR"

# fetch dependencies
composer install --optimize-autoloader
bower install

# build the project
brunch b --production

# send to server, see
rsync -az --progress --exclude-from=deploy_ignore.txt app public vendor views sogserver:/var/www/studieren-ohne-grenzen.org/dashboard

echo "## Finished deploy! ##"

# be nice
cd -
