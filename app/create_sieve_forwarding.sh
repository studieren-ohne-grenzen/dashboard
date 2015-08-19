#!/bin/bash
# call ./create-forwarding-sieve.sh $FROM $TO

from=$1
to=$2

maildir="/srv/vmail/$from"
mkdir -p "$maildir/sieve"

echo "\
require [\"copy\"];
# rule:[Weiterleitung]
if true
{
  redirect :copy \"$to\";
}" >> "$maildir/sieve/managesieve.sieve"

ln -s "$maildir/sieve/managesieve.sieve" "$maildir/active.sieve"

#make vmail user owner of all newly created files
chown -R vmail:vmail $maildir

