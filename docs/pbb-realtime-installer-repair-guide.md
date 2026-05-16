# PBB Realtime Installer Repair Guide

## Purpose

Repair mode should help recover a hub deployment with drift or partial failure.

## Typical Repair Targets

- missing `APP_KEY`
- broken `.env`
- failed migrations
- missing admin account
- missing Ratchet startup registration

## Current State

Repair mode is selectable in the installer, but the targeted repair workflow is not fully implemented yet.

Current practical use:

- re-run checks
- re-save config
- re-run install actions that are already implemented

## Future Repair Target

Repair mode should become the operator-safe way to restore a hub deployment without doing a full reinstall.
