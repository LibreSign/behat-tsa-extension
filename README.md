# behat-tsa-extension

Behat extension that starts a local RFC3161 TSA server for deterministic integration tests.

## Why

Public TSA providers can introduce flaky CI runs because of network and availability variance.
This extension boots a local TSA responder during Behat execution and exposes its URL through an environment variable.

## Installation

composer require --dev libresign/behat-tsa-extension

## Configuration

default:
  extensions:
    LibreSign\Behat\TsaExtension\ServiceContainer\TsaExtension:
      enabled: true
      host: 127.0.0.1
      port: 0
      path: /tsr
      policy_oid: 1.2.3.4.1
      env_var: LIBRESIGN_TSA_URL
      verbose: false

## How it works

- On suite start, it creates a temporary local TSA setup (CA, TSA cert, OpenSSL config).
- It starts a local HTTP server that replies with a valid RFC3161 response using OpenSSL.
- It exports the final endpoint in the configured environment variable.
- On suite finish, it stops the server and cleans temporary files.

## Requirements

- PHP 8.2+
- OpenSSL with openssl ts support
- Behat 3.13+

## License

AGPL-3.0-or-later
