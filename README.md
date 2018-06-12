[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
![Packagist][packagist]

[packagist]: https://img.shields.io/packagist/v/flownative/pixxio.svg

# pixx.io adaptor for Neos 4.x

This [Flow](https://flow.neos.io) package allows you to use assets (ie. pictures and other documents) stored in [pixx.io](https://www.pixxio-bildverwaltung.de/)
in your Neos website as if these assets were native Neos assets.

## About pixx.io
pixx.io offers an intelligent solution for digital asset management. The software makes working with
pictures, graphics and video files easier. pixx.io is safe, efficient and easy to understand and handle.

## Key Features

- authentication setup via own backend module
- seamless integration into the Neos media browser
- automatic import and clean up of media assets from pixx.io

## Installation

The pixx.io connector is installed as a regular Flow package via Composer. For your existing
project, simply include `flownative/pixxio` into the dependencies of your Flow or Neos distribution:

For Neos 4.*:

```bash
$ composer require flownative/pixxio:~1.0
```

## Enabling pixx.io API access

The API access is configured by three components:

1. a setting which contains the customer-specific service endpoint URL
2. a setting providing the pixx.io API key
3. a setting providing the pixx.io user refresh token

First define the customer-specific service endpoint by adding the URL to your settings:

```yaml
Neos:
  Media:
    assetSources:
      'flownative-pixxio':
        assetSource: 'Flownative\Pixxio\AssetSource\PixxioAssetSource'
        assetSourceOptions:
          apiEndpointUri: 'https://flownative.pixxio.media/cgi-bin/api/pixxio-api.pl'
```

You will likely just replace "flownative" by our own subdomain.

Next, add the pixx.io API key and the refresh token of the pixx.io user you want to connect with Neos:

```yaml
Neos:
  Media:
    assetSources:
      'flownative-pixxio':
        assetSource: 'Flownative\Pixxio\AssetSource\PixxioAssetSource'
        assetSourceOptions:
          apiEndpointUri: 'https://flownative.pixxio.media/cgi-bin/api/pixxio-api.pl'
          apiKey: 'abcdef123456789'
          refreshToken: 'A3ZezMq6Q24X314xbaiq5ewNE5q4Gt'
```

When you committed and deployed these changes, you can log in to the Neos backend and navigate to the pixx.io backend
module to verify your settings.
  
If you would like a separate pixx.io user for the logged in Neos user, you can copy and paste your own "refresh token"
into the form found in the backend module and store it along with your Neos user.

When everything works out fine, Neos will report that the connection was successful (and if not, you'll see an error
message with further details).

## Credits and license

This plugin was sponsored by [pixx.io](https://www.pixxio-bildverwaltung.de/) and its initial version was developed by
Robert Lemke of [Flownative](https://www.flownative.com).

See LICENSE for license details.
