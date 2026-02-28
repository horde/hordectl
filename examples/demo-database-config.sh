#!/bin/bash
#
# Demonstration of hordectl configure database command
#
# This script shows all the different ways to use the database configuration
# command, including interactive mode, CLI arguments, and testing.

set -e

echo "=========================================="
echo "hordectl configure database - Demonstration"
echo "=========================================="
echo

# Create temporary Horde installation directory for demo
DEMO_DIR=$(mktemp -d /tmp/hordectl-demo-XXXXXX)
echo "Demo directory: $DEMO_DIR"
echo

# Set up basic structure
mkdir -p "$DEMO_DIR/config"
echo "<?php" > "$DEMO_DIR/config/conf.php"

# Export for hordectl
export HORDE_INSTALL_DIR="$DEMO_DIR"

echo "1. Show help"
echo "------------"
../bin/hordectl configure database --help | head -20
echo
read -p "Press Enter to continue..."
echo

echo "2. Show current configuration (empty)"
echo "--------------------------------------"
../bin/hordectl configure database --show
echo
read -p "Press Enter to continue..."
echo

echo "3. Configure database using CLI arguments"
echo "-----------------------------------------"
echo "Command:"
echo "../bin/hordectl configure database \\"
echo "  --type mysql \\"
echo "  --host localhost \\"
echo "  --username horde \\"
echo "  --password secret \\"
echo "  --database horde \\"
echo "  --charset utf8mb4"
echo
../bin/hordectl configure database \
  --type mysql \
  --host localhost \
  --username horde \
  --password secret \
  --database horde \
  --charset utf8mb4
echo
read -p "Press Enter to continue..."
echo

echo "4. Show updated configuration"
echo "-----------------------------"
../bin/hordectl configure database --show
echo
read -p "Press Enter to continue..."
echo

echo "5. Test connection (will fail - no MySQL running)"
echo "-------------------------------------------------"
../bin/hordectl configure database --test || echo "Expected failure - no database running"
echo
read -p "Press Enter to continue..."
echo

echo "6. Configure SQLite (file-based, no connection test)"
echo "----------------------------------------------------"
echo "Command:"
echo "../bin/hordectl configure database \\"
echo "  --type sqlite \\"
echo "  --database $DEMO_DIR/horde.db"
echo
../bin/hordectl configure database \
  --type sqlite \
  --database "$DEMO_DIR/horde.db"
echo
read -p "Press Enter to continue..."
echo

echo "7. Show SQLite configuration"
echo "----------------------------"
../bin/hordectl configure database --show
echo
read -p "Press Enter to continue..."
echo

echo "8. View the generated config file"
echo "---------------------------------"
echo "File: $DEMO_DIR/config/conf.php"
echo
cat "$DEMO_DIR/config/conf.php"
echo
read -p "Press Enter to continue..."
echo

echo "9. View the backup file"
echo "-----------------------"
echo "File: $DEMO_DIR/config/conf.bak.php"
echo
if [ -f "$DEMO_DIR/config/conf.bak.php" ]; then
    cat "$DEMO_DIR/config/conf.bak.php"
else
    echo "(No backup - first save doesn't create backup)"
fi
echo
read -p "Press Enter to continue..."
echo

echo "10. Update single value"
echo "----------------------"
echo "Command:"
echo "../bin/hordectl configure database --charset utf8"
echo
../bin/hordectl configure database --charset utf8
echo
read -p "Press Enter to continue..."
echo

echo "11. Show final configuration"
echo "---------------------------"
../bin/hordectl configure database --show
echo

echo "=========================================="
echo "Demonstration complete!"
echo "=========================================="
echo
echo "Demo directory: $DEMO_DIR"
echo "Files created:"
ls -la "$DEMO_DIR/config/"
echo
echo "To clean up: rm -rf $DEMO_DIR"
