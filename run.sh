#!/bin/bash

cd "$(dirname "$0")" || exit 1
while :; do
    echo '';
    date "+%Y.%m.%d %H:%M:%S";
    echo "-------------------";
    php Stretcher.php;
    echo "-------------------";
    date "+%Y.%m.%d %H:%M:%S";
    sleep 12;
done
