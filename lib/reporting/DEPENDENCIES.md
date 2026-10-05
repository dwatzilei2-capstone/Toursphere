# Reporting dependencies

TourSphere's PHP runtime has no ZIP or GD extension. These application-local,
pinned dependencies provide native XLSX packaging and text-based PDF generation
without changing XAMPP's global PHP configuration. They are internal libraries;
Apache denies HTTP requests to this directory.

- **SimpleXLSXGen**, upstream commit `162a4a9b929611d69dbd6f1a7f42af483a87b537`.
  Source: https://github.com/shuchkin/simplexlsxgen .
  MIT license retained as `SimpleXLSXGen-LICENSE.md`.
  Source SHA-256: `520e7f767320dcbf05260406b4860cda6d7ad33045ebca71f53358b2af1d5a85`.
- **Dompdf 3.1.6**, official all-dependencies release archive
  https://github.com/dompdf/dompdf/releases/tag/v3.1.6 .
  Its LGPL license and bundled dependencies' licenses are retained in `dompdf/`.
  Use `dompdf/autoload.inc.php`, independently of the existing Composer loader.

No runtime CDN/download is needed. PDF remote resources, embedded PHP and
JavaScript execution are disabled. The renderer receives escaped application
text and local bundled fonts, not user HTML or URLs. Updates must be reviewed,
pinned and followed by the reporting file and regression tests.
