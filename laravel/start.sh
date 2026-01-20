#!/bin/bash

# Voltikka Laravel Startup Script
# Starts both the scheduler (for recurring jobs) and the web server

set -e

cd "$(dirname "$0")"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Starting Voltikka Laravel Application${NC}"
echo "========================================"

# Check if .env exists
if [ ! -f .env ]; then
    echo -e "${YELLOW}Warning: .env file not found. Copying from .env.example${NC}"
    cp .env.example .env
    php artisan key:generate
fi

# Run migrations if needed
echo "Checking database migrations..."
php artisan migrate --force

# Create logs directory if it doesn't exist
mkdir -p storage/logs

# Function to cleanup background processes on exit
cleanup() {
    echo ""
    echo -e "${YELLOW}Shutting down...${NC}"
    if [ ! -z "$SCHEDULER_PID" ]; then
        kill $SCHEDULER_PID 2>/dev/null || true
    fi
    if [ ! -z "$SERVER_PID" ]; then
        kill $SERVER_PID 2>/dev/null || true
    fi
    exit 0
}

trap cleanup SIGINT SIGTERM

# Start the scheduler in the background
echo -e "${GREEN}Starting scheduler (contracts:fetch daily, spot:fetch hourly)...${NC}"
php artisan schedule:work > storage/logs/scheduler.log 2>&1 &
SCHEDULER_PID=$!
echo "Scheduler started (PID: $SCHEDULER_PID)"

# Start the Laravel development server
echo -e "${GREEN}Starting Laravel server on http://127.0.0.1:8000${NC}"
php artisan serve &
SERVER_PID=$!
echo "Server started (PID: $SERVER_PID)"

echo ""
echo "========================================"
echo -e "${GREEN}Voltikka is running!${NC}"
echo ""
echo "Scheduled jobs:"
echo "  - contracts:fetch: daily at 06:00 (Europe/Helsinki)"
echo "  - spot:fetch: hourly"
echo "  - descriptions:generate: daily at 08:00 (Europe/Helsinki)"
echo ""
echo "Logs:"
echo "  - Scheduler: storage/logs/scheduler.log"
echo "  - Contracts: storage/logs/contracts-fetch.log"
echo "  - Spot prices: storage/logs/spot-fetch.log"
echo ""
echo "Press Ctrl+C to stop"
echo "========================================"

# Wait for both processes
wait
