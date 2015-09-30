#!/usr/bin/env bash

DIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
FIXTURES_DIR="$DIR/ldif"

load_fixture () {
  ldapadd -x -H ldap://127.0.0.1:3890/ -D "uid=dashboard,ou=services,o=sog-de,dc=sog" -w insecure -f $1
}

for FIXTURE in `ls ${FIXTURES_DIR}`
do
  load_fixture "${FIXTURES_DIR}/${FIXTURE}"
done;
