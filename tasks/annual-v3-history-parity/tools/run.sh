#!/bin/bash
# run.sh <db> <outdir> <dates...>
P=/private/tmp/claude-504/-Users-seppo-code-voltikka/f1924610-da72-4351-a7c2-e6df73df2854/scratchpad/parity
DB=$1; OUT=$2; shift 2
mkdir -p "$OUT"
cd "$P" && DB_CONNECTION=sqlite DB_DATABASE="$DB" DB_URL= CACHE_STORE=array QUEUE_CONNECTION=sync \
 CANONICAL_PRICING_ENABLED=true RESET_FORWARD_SHIFT_ENABLED=true \
 CONTRACT_STATISTICS_ANNUAL_METHOD_VERSION=annual_cost_as_of_v3 \
 nice -n 15 php -d memory_limit=1024M -d error_reporting=0 compare.php "$OUT" "$@" 2>&1 | grep -v -i deprecat
