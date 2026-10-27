#!/bin/bash
# Installs the four marketing agents into ~/.claude/agents
cd "$(dirname "$0")"
mkdir -p "$HOME/.claude/agents" "$HOME/Documents/marketing"
cp agents/*.md "$HOME/.claude/agents/"
echo ""
echo "הסוכנים הותקנו בתיקייה ~/.claude/agents:"
ls "$HOME/.claude/agents"
echo ""
echo "נוצרה תיקיית עבודה: ~/Documents/marketing"
echo "עכשיו פתחו את Claude Code בתיקייה הזו והקלידו /agents"
echo ""
read -n 1 -s -r -p "לחצו על מקש כלשהו כדי לסגור"
