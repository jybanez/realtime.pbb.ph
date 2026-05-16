# PBB Realtime Installer Hub Operator Guide

## Role Of The Operator

The hub operator is responsible for:

- running the installer
- providing deployment values
- confirming health and sandbox validation
- completing any proxy/TLS/manual tasks outside the installer

## Before Starting

Prepare:

- target webroot
- PHP runtime
- MySQL or MariaDB credentials
- public host name
- websocket public URL
- trusted issuer list
- token signing secret

## What The Installer Should Do

- validate the host
- write runtime configuration
- migrate the database
- bootstrap the first admin
- produce an install report

## What The Installer Does Not Yet Fully Do

- register Ratchet as a managed startup service
- verify websocket proxying all the way through `/realtime`

## Post-Install Expectations

The operator should still verify:

- public DNS
- TLS certificate binding
- reverse proxy websocket upgrade routing
- firewall rules
- sandbox connection flow
