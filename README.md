[![MIT license](http://img.shields.io/badge/license-MIT-brightgreen.svg)](http://opensource.org/licenses/MIT)
[![Packagist](https://img.shields.io/packagist/v/flownative/neos-pixxio.svg)](https://packagist.org/packages/flownative/neos-pixxio)
[![Maintenance level: Acquaintance](https://img.shields.io/badge/maintenance-%E2%99%A1-ff69b4.svg)](https://www.flownative.com/en/products/open-source.html)

# pixx.io adaptor for Neos

This [Flow](https://flow.neos.io) package allows you to use assets (ie. pictures and other documents)
stored in [pixx.io](https://www.pixx.io/) in your Neos website as if these assets were native Neos assets.

## About pixx.io

pixx.io offers an intelligent solution for digital asset management. The software makes working with
pictures, graphics and video files easier. pixx.io is safe, efficient and easy to understand and handle.

## Key Features

- authentication setup via own backend module
- seamless integration into the Neos media browser
- automatic import and clean up of media assets from pixx.io

## Installation

The pixx.io connector is installed as a regular Flow package via Composer. For your existing
project, simply include `flownative/neos-pixxio` into the dependencies of your Flow or Neos distribution:

```bash
composer require flownative/neos-pixxio
```

After installation you need to run database migrations:

```bash
./flow doctrine:migrate
```

## Enabling pixx.io API access

The API access is configured by three components:

1. a setting which contains the customer-specific service endpoint URL
2. a setting providing the pixx.io API key
3. a setting providing a shared pixx.io user refresh token

**To get the needed values for API endpoint and API key, please contact your pixx.io support contact.**

Using those values configure an asset source by adding this to your settings:

```yaml
Neos:
  Media:
    assetSources:
      # an identifier for your asset source, up to you
      'acme-pixxio':
        assetSource: 'Flownative\Pixxio\AssetSource\PixxioAssetSource'
        assetSourceOptions:
          apiEndpointUri: 'https://acme.pixxio.media/cgi-bin/api/pixxio-api.pl'
          apiKey: 'abcdef123456789'
          sharedRefreshToken: 'A3ZezMq6Q24X314xbaiq5ewNE5q4Gt'
```

When you committed and deployed these changes, you can log in to the Neos backend and navigate to the pixx.io backend
module to verify your settings.

If you would like a separate pixx.io user for the logged in Neos user, you can copy and paste your own "refresh token"
into the form found in the backend module and store it along with your Neos user.

When everything works out fine, Neos will report that the connection was successful (and if not, you'll see an error
message with further details).

## Additional configuration options

Defaults for the described settings can be found (and adjusted) in `Flownative.Pixxio.defaults.assetSourceOptions`.

### Label & description

You can configure a custom label and description for the asset source like this:

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          label: 'ACME assets'
          description: 'Our custom pixx.io assets source'
```

### Additional configuration for specific media types

During import, Neos tries to use a medium-sized version of the original instead of the high resolution file uploaded to
pixx.io. This greatly improves import speed and produces good results in most cases. Furthermore, this way some formats,
like Adobe Photoshop, can be used seamlessly in Neos without the need to prior converting them into a web-compatible image
format.

It is possible though, to configure this plugin to always use the high-res original for import. By default, formats like
SVG or PDF are imported this way. You can add more types through the similar entries like in the following settings:

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          mediaTypes:
            'image/svg+xml':
              usePixxioThumbnailAsOriginal: true
            'application/pdf':
              usePixxioThumbnailAsOriginal: true
```

### Custom API client options

Sometimes the API Client needs additional configuration for the tls connection like custom timeouts or certificates.
See: http://docs.guzzlephp.org/en/6.5/request-options.html

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          apiClientOptions:
            'verify': '/path/to/cert.pem'
```

## Using ImageOptions parameters for files

Via configuration, you can set what dimensions the returned images must have. The sizes are defined by the following keys:
 * `thumbnailUri` used in the media browser list
 * `previewUri` used in the detail page of a asset
 * `originalUri` used for downloading the asset

Each can be overridden from your own configuration, by addressing the specific preset key.

By default, the assets from Pixx.io is returned in a cropped format. When this is the case,
a editor can't see if a asset is horizontal or vertical, when looking in the Media Browser list.
By setting `crop: false` the image will be returned in a not-cropped version, and it's visible
for the editor, to see the assets orientation.

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          imageOptions:
            thumbnailUri:
              crop: false
```

## Cleaning up unused assets

Whenever a pixx.io asset is used in Neos, the media file will be copied automatically to the internal Neos asset
storage. As long as this media is used somewhere on the website, Neos will flag this asset as being in use.
When an asset is not used anymore, the binary data and the corresponding metadata can be removed from the internal
storage. While this does not happen automatically, it can be easily automated by a recurring task, such as a cron-job.

In order to clean up unused assets, simply run the following command as often as you like:

```bash
./flow media:removeunused --asset-source acme-pixxio
```

If you'd rather like to invoke this command through a cron-job, you can add two additional flags which make this
command non-interactive:

```bash
./flow media:removeunused --quiet --assume-yes --asset-source acme-pixxio
```

## Auto-Tagging

This plugin also offers an auto-tagging feature. When auto-tagging is enabled, Neos will automatically flag assets
which are currently used with a user-defined keyword. When as the asset is not used in Neos anymore, this keyword
is removed. This keyword is applied to the actual file / asset in the pixx.io media library and helps editors to keep
an overview of which assets are currently used by Neos.

Auto-tagging is configured as follows:

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          autoTagging:
            enable: true
            # optional, used-by-neos is the default tag
            inUseTag: 'used-by-neos'
```

Since Neos currently cannot handle auto-tagging reliably during runtime, the job must be done through a
command line command. Simply run the following command for tagging new assets and removing tags from
assets which are not in use anymore:

```
./flow pixxio:tagusedassets --asset-source acme-pixxio

Tagging used assets of asset source "acme-pixxio" via Pixxio API:
  (tagged)  dana-devolk-1348553-unsplash 358 (1)
   tagged   azamat-zhanisov-1348039-unsplash 354 (1)
  (tagged)  tim-foster-1345174-unsplash 373 (1)
   removed  some-coffee 28 (0)
  (tagged)  nikhita-s-615116-unsplash 368 (1)
```

It is recommended to run this command through a cron-job, ideally in combination with the `media:removeunused`
command. It's important to run the `removeunused`-command *after* the tagging command, because otherwise removed
images will not be untagged in the pixx.io media library.

---

**NOTE**  
At this point, the auto-tagging feature is not really optimized for performance. The command merely
iterates over all assets which were imported from pixx.io and checks if tags need to be updated.

---

### Category mapping from pixx.io to Neos

pixx.io offers categories to organize assets in a folder-like structure. Those
can be mapped to asset collections and tags in Neos, to make them visible for
the  users.

The configuration for the category import looks like this:

```yaml
Neos:
  Media:
    assetSources:
      'acme-pixxio':
        assetSourceOptions:
          mapping:
            # map "categories" from pixx.io to Neos
            categoriesMaximumDepth: 2         # only include the first two levels of categories (10 is default)
            categories:
              'People/Employees':
                asAssetCollection: false      # ignore this category, put more specific patterns first
              'People*':                      # the category "path" in pixx.io, shell-style globbing is supported
                asAssetCollection: true       # map to an asset collection named after the category
```

- The key used is the category identifier from pixx.io as used in the API, without leading slash
- `asAssetCollection` set to `true` exposes the category as an asset collection named like the category.

Afterwards, run the following command to update the asset collections, ideally
in a cronjob to keep things up-to-date:

```bash
./flow pixxio:importcategoriesascollections --asset-source acme-pixxio
```

To check what a given category would import, you can use a verbose dry-run:

```bash
$ ./flow pixxio:importcategoriesascollections --asset-source acme-pixxio --quiet 0 --dry-run 1
Importing categories as asset collections via pixx.io API
o Dokumentation
= Kunde A
= Kunde A/Projekt 1
o Kunde A/Projekt 1/Copy
o Kunde A/Projekt 1/Design
+ Kunde A/Projekt 2
o Kunde A/Projekt 2/Copy
o Kunde A/Projekt 2/Design
o Marketing
o home
o home/Doe_John
Import done.
```
The above would have added "Kunde A/Projekt 2", the items marked "=" exist already,
and everything else is ignored.

## Background and Demo

[![Background and Demo](https://img.youtube.com/vi/nG05nn-Yd0I/0.jpg)](https://www.youtube.com/watch?v=nG05nn-Yd0I)

## Credits and license

The first version of this plugin was sponsored by [pixx.io](https://www.pixxio-bildverwaltung.de/) and its initial
version was developed by Robert Lemke of [Flownative](https://www.flownative.com).

See LICENSE for license details.
