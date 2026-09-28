#!/bin/bash
# Deploy-failure test: hangs on purpose to see whether the platform enforces
# a build timeout, and if so how long it waits and what it shows meanwhile.
echo "setup-hang test: sleeping forever"
sleep 3600
