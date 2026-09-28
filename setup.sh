#!/bin/bash
# Deploy-failure test: exits non-zero on purpose to see how the php-runner
# handles a failing setup.sh (does the app come up anyway, does it show a
# clear build-failed state, what do the logs say).
echo "setup-fail test: about to exit 1"
exit 1
