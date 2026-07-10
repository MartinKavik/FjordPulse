SHELL := /usr/bin/env bash

.PHONY: help verify-planning show-goal

help:
	@printf '%s\n' \
	  'make verify-planning  Verify the consolidated planning skeleton' \
	  'make show-goal        Print the Codex implementation goal'

verify-planning:
	@bash scripts/verify-planning-inputs.sh

show-goal:
	@cat GOAL.md
