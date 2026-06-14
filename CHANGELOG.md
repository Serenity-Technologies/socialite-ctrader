# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Integrated `google/protobuf` for reliable user profile retrieval.
- Generated PHP classes from cTrader Open API `.proto` files.
- Added binary message framing for communication with cTrader Open API port 5035.
- Proper retrieval of `userId` using `ProtoOAGetCtidProfileByTokenReq` message.

### Changed
- Switched from JSON-over-socket (port 5036) to binary Protobuf protocol (port 5035) for profile retrieval.
- Updated `SocialiteProviders\Ctrader\Provider::getUserByToken` to use binary Protobuf messages.
- Updated `mapUserToObject` to utilize the verified `userId` from the profile response.

### Fixed
- Authentication flow now correctly identifies the user using the official Open API profile message instead of relying on the access token as the identifier.
