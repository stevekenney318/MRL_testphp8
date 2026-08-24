<?php
declare(strict_types=1);

/**
 * MRL SNAPSHOT COMPANION GENERATION INSTALLER
 * VERSION: v001
 * LAST MODIFIED: 7/19/2026 1:35:18 pm
 * TIME ZONE: America/New_York
 *
 * Installs:
 * - race_results_snapshot_views_helper.php v001
 * - race_results_monitor.php v138
 * - race_results_revision_monitor.php v013
 *
 * Upload to /race_results/, run once, verify all-green report, then delete.
 */

date_default_timezone_set('America/New_York');
ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = __DIR__;
$stamp = date('Ymd_His');
$report = [];
$failed = false;

$helperContent = base64_decode('PD9waHAKZGVjbGFyZShzdHJpY3RfdHlwZXM9MSk7CgovKioKICogcmFjZV9yZXN1bHRzX3NuYXBzaG90X3ZpZXdzX2hlbHBlci5waHAKICoKICogVkVSU0lPTjogdjAwMQogKiBMQVNUIE1PRElGSUVEOiA3LzE5LzIwMjYgMTozNToxOCBwbQogKgogKiBQVVJQT1NFOgogKiAtIEdlbmVyYXRlIHRoZSBjb21wbGV0ZSBjb21wYW5pb24gZmFtaWx5IGZvciBvbmUgYWNjZXB0ZWQgY2Fub25pY2FsIHNuYXBzaG90OgogKiAgICAgc25hcHNob3RfLi4uX2xpdGUuaHRtbAogKiAgICAgc25hcHNob3RfLi4uX21ybC5odG1sCiAqICAgICBzbmFwc2hvdF8uLi5fbXJsX3NlZ21lbnQuaHRtbAogKiAtIEtlZXAgZ2VuZXJhdGlvbiBzZXBhcmF0ZSBmcm9tIGNvbXBhcmlzb24vY2xhc3NpZmljYXRpb24vdmVyc2lvbiBkaXNwbGF5LgogKiAtIFBIUCA3LjMgY29tcGF0aWJsZS4KICoKICogQ0hBTkdFTE9HOgogKiB2MDAxICg3LzE5LzIwMjYgMTozNToxOCBwbSkKICogICAtIE5FVzogU2hhcmVkIGNhbm9uaWNhbCAtPiBfbGl0ZSAtPiBfbXJsIC0+IF9tcmxfc2VnbWVudCBnZW5lcmF0aW9uLgogKiAgIC0gTkVXOiBfbXJsIHVzZXMgYWxsIEEvQi9DL0QgZHJpdmVycyBsaXN0ZWQgZm9yIHRoZSBzZWxlY3RlZCB5ZWFyLgogKiAgIC0gTkVXOiBfbXJsX3NlZ21lbnQgdXNlcyBldmVyeSBkcml2ZXIgYXBwZWFyaW5nIGFueXdoZXJlIGluIHVzZXJfcGlja3MgZm9yIHRoYXQgc2VnbWVudCwKICogICAgICAgICAgaW5jbHVkaW5nIFNFRywgTFAsIFJELCBhbmQgYW55IG90aGVyIHN0b3JlZCBwaWNrIHR5cGUuCiAqICAgLSBORVc6IEdlbmVyYXRlZCB0aXRsZXMgaW5jbHVkZSByZXRhaW5lZC9zb3VyY2UgY291bnRzLCBzdWNoIGFzICgyMC8zOCkuCiAqICAgLSBORVc6IFByZXNlcnZlcyB0aGUgTVJMIExpdGUgY29sdW1uLWhlYWRlciByb3cgYW5kIG9yaWdpbmFsIE5BU0NBUiBmaW5pc2hpbmcgcG9zaXRpb25zLgogKi8KCmRhdGVfZGVmYXVsdF90aW1lem9uZV9zZXQoJ0FtZXJpY2EvTmV3X1lvcmsnKTsKCmZ1bmN0aW9uIHJyc3ZfaChzdHJpbmcgJHZhbHVlKTogc3RyaW5nCnsKICAgIHJldHVybiBodG1sc3BlY2lhbGNoYXJzKCR2YWx1ZSwgRU5UX1FVT1RFUywgJ1VURi04Jyk7Cn0KCmZ1bmN0aW9uIHJyc3ZfbnMoc3RyaW5nICR2YWx1ZSk6IHN0cmluZwp7CiAgICByZXR1cm4gdHJpbSgoc3RyaW5nKXByZWdfcmVwbGFjZSgnL1xzKy8nLCAnICcsICR2YWx1ZSkpOwp9CgpmdW5jdGlvbiBycnN2X25hbWVfa2V5KHN0cmluZyAkbmFtZSk6IHN0cmluZwp7CiAgICAkbmFtZSA9IGh0bWxfZW50aXR5X2RlY29kZSgkbmFtZSwgRU5UX1FVT1RFUyB8IEVOVF9IVE1MNSwgJ1VURi04Jyk7CiAgICAkbmFtZSA9IHJyc3ZfbnMoJG5hbWUpOwogICAgJG5hbWUgPSBzdHJfcmVwbGFjZShbIlx4QzJceEEwIiwgJ+KAmSddLCBbJyAnLCAiJyJdLCAkbmFtZSk7CiAgICAkbmFtZSA9IHByZWdfcmVwbGFjZSgnL1xzK1woW0EtWmEtejAtOSAtXStcKSQvJywgJycsICRuYW1lKTsKICAgIHJldHVybiBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykkbmFtZSkpOwp9CgpmdW5jdGlvbiBycnN2X3NlZ21lbnRfZnJvbV9yYWNlX251bWJlcihpbnQgJHJhY2VOdW1iZXIpOiBzdHJpbmcKewogICAgaWYgKCRyYWNlTnVtYmVyID49IDEgJiYgJHJhY2VOdW1iZXIgPD0gOCkgcmV0dXJuICdTMSc7CiAgICBpZiAoJHJhY2VOdW1iZXIgPj0gOSAmJiAkcmFjZU51bWJlciA8PSAxNykgcmV0dXJuICdTMic7CiAgICBpZiAoJHJhY2VOdW1iZXIgPj0gMTggJiYgJHJhY2VOdW1iZXIgPD0gMjYpIHJldHVybiAnUzMnOwogICAgaWYgKCRyYWNlTnVtYmVyID49IDI3ICYmICRyYWNlTnVtYmVyIDw9IDM2KSByZXR1cm4gJ1M0JzsKICAgIHJldHVybiAnJzsKfQoKZnVuY3Rpb24gcnJzdl9yYWNlX2NvZGVfZnJvbV9udW1iZXIoaW50ICRyYWNlTnVtYmVyKTogc3RyaW5nCnsKICAgIHJldHVybiAnUicgLiBzdHJfcGFkKChzdHJpbmcpJHJhY2VOdW1iZXIsIDIsICcwJywgU1RSX1BBRF9MRUZUKTsKfQoKZnVuY3Rpb24gcnJzdl9zaG9ydF9uYW1lX2Zyb21fZm9sZGVyKHN0cmluZyAkZm9sZGVyKTogc3RyaW5nCnsKICAgICRuYW1lID0gcHJlZ19yZXBsYWNlKCcvXltSRV1cZCtfPy9pJywgJycsICRmb2xkZXIpOwogICAgJG5hbWUgPSBwcmVnX3JlcGxhY2UoJy9fXGR7MTAsfSQvJywgJycsIChzdHJpbmcpJG5hbWUpOwogICAgJG5hbWUgPSBwcmVnX3JlcGxhY2UoJy9eTkFTQ0FSX0N1cF9TZXJpZXNfYXRfL2knLCAnJywgKHN0cmluZykkbmFtZSk7CiAgICAkbmFtZSA9IHJyc3ZfbnMoc3RyX3JlcGxhY2UoJ18nLCAnICcsIChzdHJpbmcpJG5hbWUpKTsKICAgIGlmICgkbmFtZSA9PT0gJ0NpcmN1aXQgb2YgdGhlIEFtZXJpY2FzJykgcmV0dXJuICdDT1RBJzsKICAgIHJldHVybiAkbmFtZTsKfQoKZnVuY3Rpb24gcnJzdl9zbmFwc2hvdF9wYXJ0cyhzdHJpbmcgJGNhbm9uaWNhbEJhc2UpOiA/YXJyYXkKewogICAgaWYgKCFwcmVnX21hdGNoKCcvXnNuYXBzaG90XyhcZHs4fSlfKFxkezl9KVwuaHRtbCQvJywgJGNhbm9uaWNhbEJhc2UsICRtKSkgewogICAgICAgIHJldHVybiBudWxsOwogICAgfQoKICAgICRkdCA9IERhdGVUaW1lOjpjcmVhdGVGcm9tRm9ybWF0KCdZbWQgSGlzJywgJG1bMV0gLiAnICcgLiBzdWJzdHIoJG1bMl0sIDAsIDYpKTsKICAgIGlmICghJGR0KSByZXR1cm4gbnVsbDsKCiAgICByZXR1cm4gWwogICAgICAgICdzdGFtcCcgPT4gJG1bMV0gLiAnXycgLiAkbVsyXSwKICAgICAgICAnZGlzcGxheScgPT4gJGR0LT5mb3JtYXQoJ24vai95IGc6aWEnKSwKICAgIF07Cn0KCmZ1bmN0aW9uIHJyc3ZfY2Fub25pY2FsX2ZpbGVzKHN0cmluZyAkcmFjZUZvbGRlcik6IGFycmF5CnsKICAgICRmaWxlcyA9IGdsb2IoJHJhY2VGb2xkZXIgLiBESVJFQ1RPUllfU0VQQVJBVE9SIC4gJ3NuYXBzaG90XyouaHRtbCcpID86IFtdOwogICAgJG91dCA9IFtdOwoKICAgIGZvcmVhY2ggKCRmaWxlcyBhcyAkZmlsZSkgewogICAgICAgIGlmIChwcmVnX21hdGNoKCcvXnNuYXBzaG90X1xkezh9X1xkezl9XC5odG1sJC8nLCBiYXNlbmFtZSgoc3RyaW5nKSRmaWxlKSkpIHsKICAgICAgICAgICAgJG91dFtdID0gKHN0cmluZykkZmlsZTsKICAgICAgICB9CiAgICB9CgogICAgc29ydCgkb3V0LCBTT1JUX1NUUklORyk7CiAgICByZXR1cm4gJG91dDsKfQoKZnVuY3Rpb24gcnJzdl9zbmFwc2hvdF92ZXJzaW9uKHN0cmluZyAkY2Fub25pY2FsUGF0aCk6IGludAp7CiAgICAkZmlsZXMgPSBycnN2X2Nhbm9uaWNhbF9maWxlcyhkaXJuYW1lKCRjYW5vbmljYWxQYXRoKSk7CiAgICAkYmFzZSA9IGJhc2VuYW1lKCRjYW5vbmljYWxQYXRoKTsKCiAgICBmb3JlYWNoICgkZmlsZXMgYXMgJGluZGV4ID0+ICRmaWxlKSB7CiAgICAgICAgaWYgKGJhc2VuYW1lKCRmaWxlKSA9PT0gJGJhc2UpIHsKICAgICAgICAgICAgcmV0dXJuICRpbmRleCArIDE7CiAgICAgICAgfQogICAgfQoKICAgIHJldHVybiBjb3VudCgkZmlsZXMpID4gMCA/IGNvdW50KCRmaWxlcykgOiAxOwp9CgpmdW5jdGlvbiBycnN2X2F0b21pY193cml0ZShzdHJpbmcgJHBhdGgsIHN0cmluZyAkY29udGVudCk6IGJvb2wKewogICAgJGRpciA9IGRpcm5hbWUoJHBhdGgpOwogICAgaWYgKCFpc19kaXIoJGRpcikpIHJldHVybiBmYWxzZTsKCiAgICAkdG1wID0gJHBhdGggLiAnLnRtcF8nIC4gc3RyX3JlcGxhY2UoJy4nLCAnJywgdW5pcWlkKCcnLCB0cnVlKSk7CiAgICAkYnl0ZXMgPSBAZmlsZV9wdXRfY29udGVudHMoJHRtcCwgJGNvbnRlbnQsIExPQ0tfRVgpOwogICAgaWYgKCRieXRlcyA9PT0gZmFsc2UpIHsKICAgICAgICBAdW5saW5rKCR0bXApOwogICAgICAgIHJldHVybiBmYWxzZTsKICAgIH0KCiAgICBpZiAoIUByZW5hbWUoJHRtcCwgJHBhdGgpKSB7CiAgICAgICAgQHVubGluaygkdG1wKTsKICAgICAgICByZXR1cm4gZmFsc2U7CiAgICB9CgogICAgcmV0dXJuIHRydWU7Cn0KCmZ1bmN0aW9uIHJyc3Zfb3V0ZXJfaHRtbChET01Eb2N1bWVudCAkZG9tLCBET01Ob2RlICRub2RlKTogc3RyaW5nCnsKICAgIHJldHVybiAoc3RyaW5nKSRkb20tPnNhdmVIVE1MKCRub2RlKTsKfQoKZnVuY3Rpb24gcnJzdl9jcmVhdGVfbGl0ZV9odG1sKHN0cmluZyAkY2Fub25pY2FsUGF0aCwgc3RyaW5nICR0aXRsZSk6IGFycmF5CnsKICAgICRyYXcgPSBAZmlsZV9nZXRfY29udGVudHMoJGNhbm9uaWNhbFBhdGgpOwogICAgaWYgKCRyYXcgPT09IGZhbHNlIHx8IHRyaW0oJHJhdykgPT09ICcnKSB7CiAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnZXJyb3InID0+ICdDYW5vbmljYWwgc25hcHNob3QgdW5yZWFkYWJsZSBvciBlbXB0eS4nXTsKICAgIH0KCiAgICBsaWJ4bWxfdXNlX2ludGVybmFsX2Vycm9ycyh0cnVlKTsKICAgICRkb20gPSBuZXcgRE9NRG9jdW1lbnQoKTsKICAgICRsb2FkZWQgPSBAJGRvbS0+bG9hZEhUTUwoJHJhdyk7CiAgICBsaWJ4bWxfY2xlYXJfZXJyb3JzKCk7CgogICAgaWYgKCEkbG9hZGVkKSB7CiAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnZXJyb3InID0+ICdDb3VsZCBub3QgcGFyc2UgY2Fub25pY2FsIHNuYXBzaG90IEhUTUwuJ107CiAgICB9CgogICAgJHhwID0gbmV3IERPTVhQYXRoKCRkb20pOwogICAgJHRhYmxlID0gbnVsbDsKCiAgICAkbm9kZXMgPSAkeHAtPnF1ZXJ5KCcvL3RhYmxlW2NvbnRhaW5zKGNvbmNhdCgiICIsIG5vcm1hbGl6ZS1zcGFjZShAY2xhc3MpLCAiICIpLCAiIHRhYmxlaGVhZCAiKV0nKTsKICAgIGlmICgkbm9kZXMgIT09IGZhbHNlICYmICRub2Rlcy0+bGVuZ3RoKSB7CiAgICAgICAgJHRhYmxlID0gJG5vZGVzLT5pdGVtKDApOwogICAgfQoKICAgIGlmICghJHRhYmxlIGluc3RhbmNlb2YgRE9NRWxlbWVudCkgewogICAgICAgIHJldHVybiBbJ29rJyA9PiBmYWxzZSwgJ2Vycm9yJyA9PiAnUmFjZSByZXN1bHRzIHRhYmxlIG5vdCBmb3VuZC4nXTsKICAgIH0KCiAgICAkbGlua3MgPSAkeHAtPnF1ZXJ5KCcuLy9hJywgJHRhYmxlKTsKICAgIGlmICgkbGlua3MgIT09IGZhbHNlKSB7CiAgICAgICAgZm9yICgkaSA9ICRsaW5rcy0+bGVuZ3RoIC0gMTsgJGkgPj0gMDsgJGktLSkgewogICAgICAgICAgICAkYSA9ICRsaW5rcy0+aXRlbSgkaSk7CiAgICAgICAgICAgIGlmICghJGEgfHwgISRhLT5wYXJlbnROb2RlKSBjb250aW51ZTsKICAgICAgICAgICAgd2hpbGUgKCRhLT5maXJzdENoaWxkKSB7CiAgICAgICAgICAgICAgICAkYS0+cGFyZW50Tm9kZS0+aW5zZXJ0QmVmb3JlKCRhLT5maXJzdENoaWxkLCAkYSk7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgJGEtPnBhcmVudE5vZGUtPnJlbW92ZUNoaWxkKCRhKTsKICAgICAgICB9CiAgICB9CgogICAgJHJvd3MgPSAkeHAtPnF1ZXJ5KCcuLy90cicsICR0YWJsZSk7CiAgICBpZiAoJHJvd3MgIT09IGZhbHNlICYmICRyb3dzLT5sZW5ndGgpIHsKICAgICAgICAkZmlyc3RSb3cgPSAkcm93cy0+aXRlbSgwKTsKICAgICAgICBpZiAoJGZpcnN0Um93ICYmIHN0cmNhc2VjbXAocnJzdl9ucygoc3RyaW5nKSRmaXJzdFJvdy0+dGV4dENvbnRlbnQpLCAnUmFjZSBSZXN1bHRzJykgPT09IDAgJiYgJGZpcnN0Um93LT5wYXJlbnROb2RlKSB7CiAgICAgICAgICAgICRmaXJzdFJvdy0+cGFyZW50Tm9kZS0+cmVtb3ZlQ2hpbGQoJGZpcnN0Um93KTsKICAgICAgICB9CiAgICB9CgogICAgJGhlYWRCaXRzID0gW107CiAgICAkc3R5bGVzID0gJHhwLT5xdWVyeSgnLy9oZWFkL3N0eWxlIHwgLy9oZWFkL2xpbmtbdHJhbnNsYXRlKEByZWwsIkFCQ0RFRkdISUpLTE1OT1BRUlNUVVZXWFlaIiwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoiKT0ic3R5bGVzaGVldCJdJyk7CiAgICBpZiAoJHN0eWxlcyAhPT0gZmFsc2UpIHsKICAgICAgICBmb3JlYWNoICgkc3R5bGVzIGFzICRzdHlsZU5vZGUpIHsKICAgICAgICAgICAgJGhlYWRCaXRzW10gPSBycnN2X291dGVyX2h0bWwoJGRvbSwgJHN0eWxlTm9kZSk7CiAgICAgICAgfQogICAgfQoKICAgICRib2R5Q2xhc3MgPSAnJzsKICAgICRib2RpZXMgPSAkeHAtPnF1ZXJ5KCcvL2JvZHknKTsKICAgIGlmICgkYm9kaWVzICE9PSBmYWxzZSAmJiAkYm9kaWVzLT5sZW5ndGggJiYgJGJvZGllcy0+aXRlbSgwKSBpbnN0YW5jZW9mIERPTUVsZW1lbnQpIHsKICAgICAgICAkYm9keUNsYXNzID0gJGJvZGllcy0+aXRlbSgwKS0+Z2V0QXR0cmlidXRlKCdjbGFzcycpOwogICAgfQoKICAgICR0YWJsZUh0bWwgPSBycnN2X291dGVyX2h0bWwoJGRvbSwgJHRhYmxlKTsKICAgICRmYWxsYmFjayA9ICc8c3R5bGU+aHRtbCxib2R5e21hcmdpbjowO3BhZGRpbmc6MDtiYWNrZ3JvdW5kOiNmZmZ9Ym9keXtmb250LWZhbWlseTpBcmlhbCxIZWx2ZXRpY2Esc2Fucy1zZXJpZn0ubXJsLWxpdGUtd3JhcHtkaXNwbGF5OmlubGluZS1ibG9jazttaW4td2lkdGg6Njc1cHg7bWFyZ2luOjhweDtib3JkZXI6MXB4IHNvbGlkICM4ODg7YmFja2dyb3VuZDojZmZmfS5tcmwtbGl0ZS10aXRsZXtwYWRkaW5nOjdweCAxMHB4O2JhY2tncm91bmQ6IzdhNDMwZjtjb2xvcjojZmZmO2ZvbnQtc2l6ZToxNnB4O2ZvbnQtd2VpZ2h0OjcwMDtsaW5lLWhlaWdodDoxLjJ9Lm1ybC1saXRlLXRhYmxlLXdyYXAgdGFibGV7Ym9yZGVyLWNvbGxhcHNlOmNvbGxhcHNlO3dpZHRoOjEwMCV9Lm1ybC1saXRlLXRhYmxlLXdyYXAgdGgsLm1ybC1saXRlLXRhYmxlLXdyYXAgdGR7d2hpdGUtc3BhY2U6bm93cmFwfS5tcmwtbGl0ZS10YWJsZS13cmFwIGF7Y29sb3I6aW5oZXJpdDt0ZXh0LWRlY29yYXRpb246bm9uZTtwb2ludGVyLWV2ZW50czpub25lfTwvc3R5bGU+JzsKCiAgICAkaHRtbCA9ICI8IURPQ1RZUEUgaHRtbD5cbiIKICAgICAgICAuICI8aHRtbCBsYW5nPVwiZW5cIj5cbjxoZWFkPlxuIgogICAgICAgIC4gIjxtZXRhIGNoYXJzZXQ9XCJVVEYtOFwiPlxuIgogICAgICAgIC4gIjxtZXRhIG5hbWU9XCJ2aWV3cG9ydFwiIGNvbnRlbnQ9XCJ3aWR0aD1kZXZpY2Utd2lkdGgsaW5pdGlhbC1zY2FsZT0xXCI+XG4iCiAgICAgICAgLiAiPG1ldGEgbmFtZT1cInJvYm90c1wiIGNvbnRlbnQ9XCJub2luZGV4LG5vZm9sbG93XCI+XG4iCiAgICAgICAgLiAiPHRpdGxlPiIgLiBycnN2X2goJHRpdGxlKSAuICI8L3RpdGxlPlxuIgogICAgICAgIC4gaW1wbG9kZSgiXG4iLCAkaGVhZEJpdHMpIC4gIlxuIgogICAgICAgIC4gJGZhbGxiYWNrIC4gIlxuIgogICAgICAgIC4gIjwvaGVhZD5cbiIKICAgICAgICAuICI8Ym9keSBjbGFzcz1cIiIgLiBycnN2X2goJGJvZHlDbGFzcykgLiAiXCI+XG4iCiAgICAgICAgLiAiPGRpdiBjbGFzcz1cIm1ybC1saXRlLXdyYXBcIj5cbiIKICAgICAgICAuICI8ZGl2IGNsYXNzPVwibXJsLWxpdGUtdGl0bGVcIj4iIC4gcnJzdl9oKCR0aXRsZSkgLiAiPC9kaXY+XG4iCiAgICAgICAgLiAiPGRpdiBjbGFzcz1cIm1ybC1saXRlLXRhYmxlLXdyYXBcIj5cbiIKICAgICAgICAuICR0YWJsZUh0bWwgLiAiXG4iCiAgICAgICAgLiAiPC9kaXY+XG48L2Rpdj5cbiIKICAgICAgICAuICI8IS0tIFNvdXJjZTogIiAuIHJyc3ZfaChiYXNlbmFtZSgkY2Fub25pY2FsUGF0aCkpIC4gIiAtLT5cbiIKICAgICAgICAuICI8L2JvZHk+XG48L2h0bWw+XG4iOwoKICAgIHJldHVybiBbJ29rJyA9PiB0cnVlLCAnaHRtbCcgPT4gJGh0bWxdOwp9CgpmdW5jdGlvbiBycnN2X3F1ZXJ5X3llYXJfZHJpdmVycyhzdHJpbmcgJHllYXIsICRkYm8sICRkYmNvbm5lY3QpOiBhcnJheQp7CiAgICAkbmFtZXMgPSBbXTsKICAgICR0YWJsZXMgPSBbJ0EgRHJpdmVycycsICdCIERyaXZlcnMnLCAnQyBEcml2ZXJzJywgJ0QgRHJpdmVycyddOwoKICAgIGlmICgkZGJvIGluc3RhbmNlb2YgUERPKSB7CiAgICAgICAgZm9yZWFjaCAoJHRhYmxlcyBhcyAkdGFibGUpIHsKICAgICAgICAgICAgJHN0bXQgPSAkZGJvLT5wcmVwYXJlKCdTRUxFQ1QgZHJpdmVyTmFtZSBGUk9NIGAnIC4gJHRhYmxlIC4gJ2AgV0hFUkUgZHJpdmVyWWVhciA9IDp5ZWFyJyk7CiAgICAgICAgICAgICRzdG10LT5leGVjdXRlKFsnOnllYXInID0+ICR5ZWFyXSk7CgogICAgICAgICAgICBmb3JlYWNoICgkc3RtdC0+ZmV0Y2hBbGwoUERPOjpGRVRDSF9BU1NPQykgYXMgJHJvdykgewogICAgICAgICAgICAgICAgJG5hbWUgPSB0cmltKChzdHJpbmcpKCRyb3dbJ2RyaXZlck5hbWUnXSA/PyAnJykpOwogICAgICAgICAgICAgICAgaWYgKCRuYW1lICE9PSAnJykgJG5hbWVzW3Jyc3ZfbmFtZV9rZXkoJG5hbWUpXSA9ICRuYW1lOwogICAgICAgICAgICB9CiAgICAgICAgfQogICAgICAgIHJldHVybiAkbmFtZXM7CiAgICB9CgogICAgaWYgKCRkYmNvbm5lY3QgaW5zdGFuY2VvZiBteXNxbGkpIHsKICAgICAgICBmb3JlYWNoICgkdGFibGVzIGFzICR0YWJsZSkgewogICAgICAgICAgICAkc3RtdCA9IG15c3FsaV9wcmVwYXJlKCRkYmNvbm5lY3QsICdTRUxFQ1QgZHJpdmVyTmFtZSBGUk9NIGAnIC4gJHRhYmxlIC4gJ2AgV0hFUkUgZHJpdmVyWWVhciA9ID8nKTsKICAgICAgICAgICAgaWYgKCEkc3RtdCkgY29udGludWU7CiAgICAgICAgICAgIG15c3FsaV9zdG10X2JpbmRfcGFyYW0oJHN0bXQsICdzJywgJHllYXIpOwogICAgICAgICAgICBteXNxbGlfc3RtdF9leGVjdXRlKCRzdG10KTsKICAgICAgICAgICAgJHJlc3VsdCA9IG15c3FsaV9zdG10X2dldF9yZXN1bHQoJHN0bXQpOwoKICAgICAgICAgICAgd2hpbGUgKCRyb3cgPSBteXNxbGlfZmV0Y2hfYXNzb2MoJHJlc3VsdCkpIHsKICAgICAgICAgICAgICAgICRuYW1lID0gdHJpbSgoc3RyaW5nKSgkcm93Wydkcml2ZXJOYW1lJ10gPz8gJycpKTsKICAgICAgICAgICAgICAgIGlmICgkbmFtZSAhPT0gJycpICRuYW1lc1tycnN2X25hbWVfa2V5KCRuYW1lKV0gPSAkbmFtZTsKICAgICAgICAgICAgfQogICAgICAgICAgICBteXNxbGlfc3RtdF9jbG9zZSgkc3RtdCk7CiAgICAgICAgfQogICAgfQoKICAgIHJldHVybiAkbmFtZXM7Cn0KCmZ1bmN0aW9uIHJyc3ZfcXVlcnlfc2VnbWVudF9kcml2ZXJzKHN0cmluZyAkeWVhciwgc3RyaW5nICRzZWdtZW50LCAkZGJvLCAkZGJjb25uZWN0KTogYXJyYXkKewogICAgJG5hbWVzID0gW107CgogICAgaWYgKCRkYm8gaW5zdGFuY2VvZiBQRE8pIHsKICAgICAgICAkc3RtdCA9ICRkYm8tPnByZXBhcmUoCiAgICAgICAgICAgICdTRUxFQ1QgZHJpdmVyQSwgZHJpdmVyQiwgZHJpdmVyQywgZHJpdmVyRCBGUk9NIHVzZXJfcGlja3MgJwogICAgICAgICAgICAuICdXSEVSRSByYWNlWWVhciA9IDp5ZWFyIEFORCBzZWdtZW50ID0gOnNlZ21lbnQnCiAgICAgICAgKTsKICAgICAgICAkc3RtdC0+ZXhlY3V0ZShbJzp5ZWFyJyA9PiAkeWVhciwgJzpzZWdtZW50JyA9PiAkc2VnbWVudF0pOwoKICAgICAgICBmb3JlYWNoICgkc3RtdC0+ZmV0Y2hBbGwoUERPOjpGRVRDSF9BU1NPQykgYXMgJHJvdykgewogICAgICAgICAgICBmb3JlYWNoIChbJ2RyaXZlckEnLCAnZHJpdmVyQicsICdkcml2ZXJDJywgJ2RyaXZlckQnXSBhcyAkZmllbGQpIHsKICAgICAgICAgICAgICAgICRuYW1lID0gdHJpbSgoc3RyaW5nKSgkcm93WyRmaWVsZF0gPz8gJycpKTsKICAgICAgICAgICAgICAgIGlmICgkbmFtZSAhPT0gJycpICRuYW1lc1tycnN2X25hbWVfa2V5KCRuYW1lKV0gPSAkbmFtZTsKICAgICAgICAgICAgfQogICAgICAgIH0KICAgICAgICByZXR1cm4gJG5hbWVzOwogICAgfQoKICAgIGlmICgkZGJjb25uZWN0IGluc3RhbmNlb2YgbXlzcWxpKSB7CiAgICAgICAgJHN0bXQgPSBteXNxbGlfcHJlcGFyZSgKICAgICAgICAgICAgJGRiY29ubmVjdCwKICAgICAgICAgICAgJ1NFTEVDVCBkcml2ZXJBLCBkcml2ZXJCLCBkcml2ZXJDLCBkcml2ZXJEIEZST00gdXNlcl9waWNrcyBXSEVSRSByYWNlWWVhciA9ID8gQU5EIHNlZ21lbnQgPSA/JwogICAgICAgICk7CiAgICAgICAgaWYgKCRzdG10KSB7CiAgICAgICAgICAgIG15c3FsaV9zdG10X2JpbmRfcGFyYW0oJHN0bXQsICdzcycsICR5ZWFyLCAkc2VnbWVudCk7CiAgICAgICAgICAgIG15c3FsaV9zdG10X2V4ZWN1dGUoJHN0bXQpOwogICAgICAgICAgICAkcmVzdWx0ID0gbXlzcWxpX3N0bXRfZ2V0X3Jlc3VsdCgkc3RtdCk7CgogICAgICAgICAgICB3aGlsZSAoJHJvdyA9IG15c3FsaV9mZXRjaF9hc3NvYygkcmVzdWx0KSkgewogICAgICAgICAgICAgICAgZm9yZWFjaCAoWydkcml2ZXJBJywgJ2RyaXZlckInLCAnZHJpdmVyQycsICdkcml2ZXJEJ10gYXMgJGZpZWxkKSB7CiAgICAgICAgICAgICAgICAgICAgJG5hbWUgPSB0cmltKChzdHJpbmcpKCRyb3dbJGZpZWxkXSA/PyAnJykpOwogICAgICAgICAgICAgICAgICAgIGlmICgkbmFtZSAhPT0gJycpICRuYW1lc1tycnN2X25hbWVfa2V5KCRuYW1lKV0gPSAkbmFtZTsKICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgfQogICAgICAgICAgICBteXNxbGlfc3RtdF9jbG9zZSgkc3RtdCk7CiAgICAgICAgfQogICAgfQoKICAgIHJldHVybiAkbmFtZXM7Cn0KCmZ1bmN0aW9uIHJyc3ZfZmlsdGVyX2xpdGVfaHRtbChzdHJpbmcgJGxpdGVIdG1sLCBhcnJheSAkYWxsb3dlZERyaXZlcnMsIHN0cmluZyAkdmlld0xhYmVsKTogYXJyYXkKewogICAgbGlieG1sX3VzZV9pbnRlcm5hbF9lcnJvcnModHJ1ZSk7CiAgICAkZG9tID0gbmV3IERPTURvY3VtZW50KCk7CiAgICAkbG9hZGVkID0gQCRkb20tPmxvYWRIVE1MKCRsaXRlSHRtbCk7CiAgICBsaWJ4bWxfY2xlYXJfZXJyb3JzKCk7CgogICAgaWYgKCEkbG9hZGVkKSB7CiAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnZXJyb3InID0+ICdDb3VsZCBub3QgcGFyc2UgZ2VuZXJhdGVkIGxpdGUgSFRNTC4nXTsKICAgIH0KCiAgICAkeHAgPSBuZXcgRE9NWFBhdGgoJGRvbSk7CiAgICAkbm9kZXMgPSAkeHAtPnF1ZXJ5KCcvL3RhYmxlW2NvbnRhaW5zKGNvbmNhdCgiICIsIG5vcm1hbGl6ZS1zcGFjZShAY2xhc3MpLCAiICIpLCAiIHRhYmxlaGVhZCAiKV0nKTsKICAgICR0YWJsZSA9ICgkbm9kZXMgIT09IGZhbHNlICYmICRub2Rlcy0+bGVuZ3RoKSA/ICRub2Rlcy0+aXRlbSgwKSA6IG51bGw7CgogICAgaWYgKCEkdGFibGUgaW5zdGFuY2VvZiBET01FbGVtZW50KSB7CiAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnZXJyb3InID0+ICdNUkwgTGl0ZSByZXN1bHRzIHRhYmxlIG5vdCBmb3VuZC4nXTsKICAgIH0KCiAgICAkZHJpdmVySW5kZXggPSAxOwogICAgJGtlcHQgPSAwOwogICAgJHJlbW92ZWQgPSAwOwogICAgJHNvdXJjZURyaXZlckNvdW50ID0gMDsKICAgICRyb3dzID0gJHhwLT5xdWVyeSgnLi8vdHJbdGRdJywgJHRhYmxlKTsKCiAgICBpZiAoJHJvd3MgIT09IGZhbHNlKSB7CiAgICAgICAgZm9yICgkaSA9ICRyb3dzLT5sZW5ndGggLSAxOyAkaSA+PSAwOyAkaS0tKSB7CiAgICAgICAgICAgICRyb3cgPSAkcm93cy0+aXRlbSgkaSk7CiAgICAgICAgICAgIGlmICghJHJvdyB8fCAhJHJvdy0+cGFyZW50Tm9kZSkgY29udGludWU7CgogICAgICAgICAgICAkcm93Q2xhc3MgPSAnJzsKICAgICAgICAgICAgaWYgKCRyb3ctPmF0dHJpYnV0ZXMgJiYgJHJvdy0+YXR0cmlidXRlcy0+Z2V0TmFtZWRJdGVtKCdjbGFzcycpKSB7CiAgICAgICAgICAgICAgICAkcm93Q2xhc3MgPSAnICcgLiBycnN2X25zKChzdHJpbmcpJHJvdy0+YXR0cmlidXRlcy0+Z2V0TmFtZWRJdGVtKCdjbGFzcycpLT5ub2RlVmFsdWUpIC4gJyAnOwogICAgICAgICAgICB9CgogICAgICAgICAgICBpZiAoc3RycG9zKCRyb3dDbGFzcywgJyBjb2xoZWFkICcpICE9PSBmYWxzZSkgewogICAgICAgICAgICAgICAgY29udGludWU7CiAgICAgICAgICAgIH0KCiAgICAgICAgICAgICRjZWxscyA9ICR4cC0+cXVlcnkoJy4vdGQnLCAkcm93KTsKICAgICAgICAgICAgaWYgKCRjZWxscyA9PT0gZmFsc2UgfHwgJGNlbGxzLT5sZW5ndGggPD0gJGRyaXZlckluZGV4KSBjb250aW51ZTsKCiAgICAgICAgICAgICRzb3VyY2VEcml2ZXJDb3VudCsrOwogICAgICAgICAgICAkZHJpdmVyTmFtZSA9IHJyc3ZfbnMoKHN0cmluZykkY2VsbHMtPml0ZW0oJGRyaXZlckluZGV4KS0+dGV4dENvbnRlbnQpOwogICAgICAgICAgICAkZHJpdmVyS2V5ID0gcnJzdl9uYW1lX2tleSgkZHJpdmVyTmFtZSk7CgogICAgICAgICAgICBpZiAoJGRyaXZlcktleSAhPT0gJycgJiYgaXNzZXQoJGFsbG93ZWREcml2ZXJzWyRkcml2ZXJLZXldKSkgewogICAgICAgICAgICAgICAgJGtlcHQrKzsKICAgICAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgICAgICRyb3ctPnBhcmVudE5vZGUtPnJlbW92ZUNoaWxkKCRyb3cpOwogICAgICAgICAgICAgICAgJHJlbW92ZWQrKzsKICAgICAgICAgICAgfQogICAgICAgIH0KICAgIH0KCiAgICAkdGl0bGVOb2RlcyA9ICR4cC0+cXVlcnkoJy8vZGl2W2NvbnRhaW5zKGNvbmNhdCgiICIsIG5vcm1hbGl6ZS1zcGFjZShAY2xhc3MpLCAiICIpLCAiIG1ybC1saXRlLXRpdGxlICIpXScpOwogICAgaWYgKCR0aXRsZU5vZGVzICE9PSBmYWxzZSAmJiAkdGl0bGVOb2Rlcy0+bGVuZ3RoKSB7CiAgICAgICAgJHRpdGxlTm9kZSA9ICR0aXRsZU5vZGVzLT5pdGVtKDApOwogICAgICAgIGlmICgkdGl0bGVOb2RlKSB7CiAgICAgICAgICAgICRiYXNlVGl0bGUgPSBycnN2X25zKChzdHJpbmcpJHRpdGxlTm9kZS0+dGV4dENvbnRlbnQpOwogICAgICAgICAgICB3aGlsZSAoJHRpdGxlTm9kZS0+Zmlyc3RDaGlsZCkgewogICAgICAgICAgICAgICAgJHRpdGxlTm9kZS0+cmVtb3ZlQ2hpbGQoJHRpdGxlTm9kZS0+Zmlyc3RDaGlsZCk7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgJHRpdGxlTm9kZS0+YXBwZW5kQ2hpbGQoCiAgICAgICAgICAgICAgICAkZG9tLT5jcmVhdGVUZXh0Tm9kZSgkYmFzZVRpdGxlIC4gJyDigJQgJyAuICR2aWV3TGFiZWwgLiAnICgnIC4gJGtlcHQgLiAnLycgLiAkc291cmNlRHJpdmVyQ291bnQgLiAnKScpCiAgICAgICAgICAgICk7CiAgICAgICAgfQogICAgfQoKICAgICRjb21tZW50cyA9ICR4cC0+cXVlcnkoJy8vY29tbWVudCgpW2NvbnRhaW5zKC4sICJTb3VyY2U6IildJyk7CiAgICBpZiAoJGNvbW1lbnRzICE9PSBmYWxzZSkgewogICAgICAgIGZvcmVhY2ggKCRjb21tZW50cyBhcyAkY29tbWVudCkgewogICAgICAgICAgICBpZiAoJGNvbW1lbnQtPnBhcmVudE5vZGUpIHsKICAgICAgICAgICAgICAgICRjb21tZW50LT5wYXJlbnROb2RlLT5yZXBsYWNlQ2hpbGQoCiAgICAgICAgICAgICAgICAgICAgJGRvbS0+Y3JlYXRlQ29tbWVudCgnIFNvdXJjZTogZ2VuZXJhdGVkIGxpdGUgY29tcGFuaW9uIHwgVmlldzogJyAuICR2aWV3TGFiZWwgLiAnICcpLAogICAgICAgICAgICAgICAgICAgICRjb21tZW50CiAgICAgICAgICAgICAgICApOwogICAgICAgICAgICB9CiAgICAgICAgfQogICAgfQoKICAgICRodG1sID0gJGRvbS0+c2F2ZUhUTUwoKTsKICAgIGlmICghaXNfc3RyaW5nKCRodG1sKSB8fCB0cmltKCRodG1sKSA9PT0gJycpIHsKICAgICAgICByZXR1cm4gWydvaycgPT4gZmFsc2UsICdlcnJvcicgPT4gJ0ZpbHRlcmVkIGNvbXBhbmlvbiBIVE1MIGNvdWxkIG5vdCBiZSBnZW5lcmF0ZWQuJ107CiAgICB9CgogICAgcmV0dXJuIFsKICAgICAgICAnb2snID0+IHRydWUsCiAgICAgICAgJ2h0bWwnID0+ICRodG1sLAogICAgICAgICdrZXB0JyA9PiAka2VwdCwKICAgICAgICAncmVtb3ZlZCcgPT4gJHJlbW92ZWQsCiAgICAgICAgJ3NvdXJjZV9jb3VudCcgPT4gJHNvdXJjZURyaXZlckNvdW50LAogICAgXTsKfQoKZnVuY3Rpb24gcnJzdl9nZW5lcmF0ZV9jb21wYW5pb25fc2V0KAogICAgc3RyaW5nICRjYW5vbmljYWxQYXRoLAogICAgaW50ICR5ZWFyLAogICAgaW50ICRyYWNlTnVtYmVyLAogICAgc3RyaW5nICRyYWNlRm9sZGVyTmFtZSwKICAgICRkYm8gPSBudWxsLAogICAgJGRiY29ubmVjdCA9IG51bGwsCiAgICBib29sICRvdmVyd3JpdGUgPSB0cnVlCik6IGFycmF5IHsKICAgICRyZXN1bHQgPSBbCiAgICAgICAgJ29rJyA9PiBmYWxzZSwKICAgICAgICAnY2Fub25pY2FsJyA9PiBiYXNlbmFtZSgkY2Fub25pY2FsUGF0aCksCiAgICAgICAgJ2ZpbGVzJyA9PiBbXSwKICAgICAgICAnZXJyb3JzJyA9PiBbXSwKICAgICAgICAnY291bnRzJyA9PiBbXSwKICAgIF07CgogICAgaWYgKCFpc19maWxlKCRjYW5vbmljYWxQYXRoKSkgewogICAgICAgICRyZXN1bHRbJ2Vycm9ycyddW10gPSAnQ2Fub25pY2FsIHNuYXBzaG90IGZpbGUgZG9lcyBub3QgZXhpc3QuJzsKICAgICAgICByZXR1cm4gJHJlc3VsdDsKICAgIH0KCiAgICAkcGFydHMgPSBycnN2X3NuYXBzaG90X3BhcnRzKGJhc2VuYW1lKCRjYW5vbmljYWxQYXRoKSk7CiAgICBpZiAoJHBhcnRzID09PSBudWxsKSB7CiAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdDYW5vbmljYWwgc25hcHNob3QgZmlsZW5hbWUgaXMgbm90IHJlY29nbml6ZWQuJzsKICAgICAgICByZXR1cm4gJHJlc3VsdDsKICAgIH0KCiAgICAkc2VnbWVudCA9IHJyc3Zfc2VnbWVudF9mcm9tX3JhY2VfbnVtYmVyKCRyYWNlTnVtYmVyKTsKICAgIGlmICgkc2VnbWVudCA9PT0gJycpIHsKICAgICAgICAkcmVzdWx0WydlcnJvcnMnXVtdID0gJ1JhY2UgbnVtYmVyIGRvZXMgbm90IG1hcCB0byBhIHNlZ21lbnQuJzsKICAgICAgICByZXR1cm4gJHJlc3VsdDsKICAgIH0KCiAgICAkdmVyc2lvbiA9IHJyc3Zfc25hcHNob3RfdmVyc2lvbigkY2Fub25pY2FsUGF0aCk7CiAgICAkcmFjZUNvZGUgPSBycnN2X3JhY2VfY29kZV9mcm9tX251bWJlcigkcmFjZU51bWJlcik7CiAgICAkc2hvcnROYW1lID0gcnJzdl9zaG9ydF9uYW1lX2Zyb21fZm9sZGVyKCRyYWNlRm9sZGVyTmFtZSk7CiAgICAkdGl0bGUgPSAkeWVhciAuICcgJyAuICRyYWNlQ29kZSAuICcgJyAuICRzaG9ydE5hbWUgLiAnIHYnIC4gJHZlcnNpb24gLiAnICgnIC4gJHBhcnRzWydkaXNwbGF5J10gLiAnKSc7CgogICAgJGxpdGVQYXRoID0gcHJlZ19yZXBsYWNlKCcvXC5odG1sJC8nLCAnX2xpdGUuaHRtbCcsICRjYW5vbmljYWxQYXRoKTsKICAgICRtcmxQYXRoID0gcHJlZ19yZXBsYWNlKCcvXC5odG1sJC8nLCAnX21ybC5odG1sJywgJGNhbm9uaWNhbFBhdGgpOwogICAgJHNlZ21lbnRQYXRoID0gcHJlZ19yZXBsYWNlKCcvXC5odG1sJC8nLCAnX21ybF9zZWdtZW50Lmh0bWwnLCAkY2Fub25pY2FsUGF0aCk7CgogICAgJGxpdGUgPSBycnN2X2NyZWF0ZV9saXRlX2h0bWwoJGNhbm9uaWNhbFBhdGgsICR0aXRsZSk7CiAgICBpZiAoZW1wdHkoJGxpdGVbJ29rJ10pKSB7CiAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdMaXRlOiAnIC4gKHN0cmluZykoJGxpdGVbJ2Vycm9yJ10gPz8gJ3Vua25vd24gZXJyb3InKTsKICAgICAgICByZXR1cm4gJHJlc3VsdDsKICAgIH0KCiAgICBpZiAoJG92ZXJ3cml0ZSB8fCAhaXNfZmlsZSgkbGl0ZVBhdGgpKSB7CiAgICAgICAgaWYgKCFycnN2X2F0b21pY193cml0ZSgkbGl0ZVBhdGgsIChzdHJpbmcpJGxpdGVbJ2h0bWwnXSkpIHsKICAgICAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdDb3VsZCBub3Qgd3JpdGUgJyAuIGJhc2VuYW1lKCRsaXRlUGF0aCk7CiAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgJHJlc3VsdFsnZmlsZXMnXVsnbGl0ZSddID0gYmFzZW5hbWUoJGxpdGVQYXRoKTsKICAgICAgICB9CiAgICB9IGVsc2UgewogICAgICAgICRyZXN1bHRbJ2ZpbGVzJ11bJ2xpdGUnXSA9IGJhc2VuYW1lKCRsaXRlUGF0aCk7CiAgICB9CgogICAgJHllYXJEcml2ZXJzID0gcnJzdl9xdWVyeV95ZWFyX2RyaXZlcnMoKHN0cmluZykkeWVhciwgJGRibywgJGRiY29ubmVjdCk7CiAgICAkc2VnbWVudERyaXZlcnMgPSBycnN2X3F1ZXJ5X3NlZ21lbnRfZHJpdmVycygoc3RyaW5nKSR5ZWFyLCAkc2VnbWVudCwgJGRibywgJGRiY29ubmVjdCk7CgogICAgaWYgKGVtcHR5KCR5ZWFyRHJpdmVycykpIHsKICAgICAgICAkcmVzdWx0WydlcnJvcnMnXVtdID0gJ05vIE1STCB5ZWFyIGRyaXZlcnMgd2VyZSBmb3VuZC4nOwogICAgfSBlbHNlIHsKICAgICAgICAkbXJsID0gcnJzdl9maWx0ZXJfbGl0ZV9odG1sKChzdHJpbmcpJGxpdGVbJ2h0bWwnXSwgJHllYXJEcml2ZXJzLCAnTVJMIFllYXIgRHJpdmVycycpOwogICAgICAgIGlmIChlbXB0eSgkbXJsWydvayddKSkgewogICAgICAgICAgICAkcmVzdWx0WydlcnJvcnMnXVtdID0gJ01STDogJyAuIChzdHJpbmcpKCRtcmxbJ2Vycm9yJ10gPz8gJ3Vua25vd24gZXJyb3InKTsKICAgICAgICB9IGVsc2VpZiAoJG92ZXJ3cml0ZSB8fCAhaXNfZmlsZSgkbXJsUGF0aCkpIHsKICAgICAgICAgICAgaWYgKCFycnN2X2F0b21pY193cml0ZSgkbXJsUGF0aCwgKHN0cmluZykkbXJsWydodG1sJ10pKSB7CiAgICAgICAgICAgICAgICAkcmVzdWx0WydlcnJvcnMnXVtdID0gJ0NvdWxkIG5vdCB3cml0ZSAnIC4gYmFzZW5hbWUoJG1ybFBhdGgpOwogICAgICAgICAgICB9IGVsc2UgewogICAgICAgICAgICAgICAgJHJlc3VsdFsnZmlsZXMnXVsnbXJsJ10gPSBiYXNlbmFtZSgkbXJsUGF0aCk7CiAgICAgICAgICAgICAgICAkcmVzdWx0Wydjb3VudHMnXVsnbXJsJ10gPSBbCiAgICAgICAgICAgICAgICAgICAgJ2tlcHQnID0+IChpbnQpJG1ybFsna2VwdCddLAogICAgICAgICAgICAgICAgICAgICdzb3VyY2UnID0+IChpbnQpJG1ybFsnc291cmNlX2NvdW50J10sCiAgICAgICAgICAgICAgICBdOwogICAgICAgICAgICB9CiAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgJHJlc3VsdFsnZmlsZXMnXVsnbXJsJ10gPSBiYXNlbmFtZSgkbXJsUGF0aCk7CiAgICAgICAgfQogICAgfQoKICAgIGlmIChlbXB0eSgkc2VnbWVudERyaXZlcnMpKSB7CiAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdObyBNUkwgc2VnbWVudCBkcml2ZXJzIHdlcmUgZm91bmQgZm9yICcgLiAkc2VnbWVudCAuICcuJzsKICAgIH0gZWxzZSB7CiAgICAgICAgJG1ybFNlZ21lbnQgPSBycnN2X2ZpbHRlcl9saXRlX2h0bWwoCiAgICAgICAgICAgIChzdHJpbmcpJGxpdGVbJ2h0bWwnXSwKICAgICAgICAgICAgJHNlZ21lbnREcml2ZXJzLAogICAgICAgICAgICAnTVJMIFNlZ21lbnQgJyAuICRzZWdtZW50IC4gJyBEcml2ZXJzJwogICAgICAgICk7CgogICAgICAgIGlmIChlbXB0eSgkbXJsU2VnbWVudFsnb2snXSkpIHsKICAgICAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdNUkwgc2VnbWVudDogJyAuIChzdHJpbmcpKCRtcmxTZWdtZW50WydlcnJvciddID8/ICd1bmtub3duIGVycm9yJyk7CiAgICAgICAgfSBlbHNlaWYgKCRvdmVyd3JpdGUgfHwgIWlzX2ZpbGUoJHNlZ21lbnRQYXRoKSkgewogICAgICAgICAgICBpZiAoIXJyc3ZfYXRvbWljX3dyaXRlKCRzZWdtZW50UGF0aCwgKHN0cmluZykkbXJsU2VnbWVudFsnaHRtbCddKSkgewogICAgICAgICAgICAgICAgJHJlc3VsdFsnZXJyb3JzJ11bXSA9ICdDb3VsZCBub3Qgd3JpdGUgJyAuIGJhc2VuYW1lKCRzZWdtZW50UGF0aCk7CiAgICAgICAgICAgIH0gZWxzZSB7CiAgICAgICAgICAgICAgICAkcmVzdWx0WydmaWxlcyddWydtcmxfc2VnbWVudCddID0gYmFzZW5hbWUoJHNlZ21lbnRQYXRoKTsKICAgICAgICAgICAgICAgICRyZXN1bHRbJ2NvdW50cyddWydtcmxfc2VnbWVudCddID0gWwogICAgICAgICAgICAgICAgICAgICdrZXB0JyA9PiAoaW50KSRtcmxTZWdtZW50WydrZXB0J10sCiAgICAgICAgICAgICAgICAgICAgJ3NvdXJjZScgPT4gKGludCkkbXJsU2VnbWVudFsnc291cmNlX2NvdW50J10sCiAgICAgICAgICAgICAgICBdOwogICAgICAgICAgICB9CiAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgJHJlc3VsdFsnZmlsZXMnXVsnbXJsX3NlZ21lbnQnXSA9IGJhc2VuYW1lKCRzZWdtZW50UGF0aCk7CiAgICAgICAgfQogICAgfQoKICAgICRtdGltZSA9IEBmaWxlbXRpbWUoJGNhbm9uaWNhbFBhdGgpOwogICAgaWYgKCRtdGltZSAhPT0gZmFsc2UpIHsKICAgICAgICBmb3JlYWNoIChbJGxpdGVQYXRoLCAkbXJsUGF0aCwgJHNlZ21lbnRQYXRoXSBhcyAkcGF0aCkgewogICAgICAgICAgICBpZiAoaXNfZmlsZSgkcGF0aCkpIEB0b3VjaCgkcGF0aCwgJG10aW1lLCAkbXRpbWUpOwogICAgICAgIH0KICAgIH0KCiAgICAkcmVzdWx0WydvayddID0gZW1wdHkoJHJlc3VsdFsnZXJyb3JzJ10pCiAgICAgICAgJiYgaXNzZXQoJHJlc3VsdFsnZmlsZXMnXVsnbGl0ZSddKQogICAgICAgICYmIGlzc2V0KCRyZXN1bHRbJ2ZpbGVzJ11bJ21ybCddKQogICAgICAgICYmIGlzc2V0KCRyZXN1bHRbJ2ZpbGVzJ11bJ21ybF9zZWdtZW50J10pOwoKICAgIHJldHVybiAkcmVzdWx0Owp9Cg==', true);
if ($helperContent === false) {
    die('Installer payload could not be decoded.');
}

function inst_add(string $status, string $message): void
{
    global $report, $failed;
    $report[] = ['status' => $status, 'message' => $message];
    if ($status === 'ERROR') $failed = true;
}

function inst_backup(string $path, string $stamp): bool
{
    if (!is_file($path)) return false;
    return @copy($path, $path . '.bak_' . $stamp);
}

function inst_replace_once(string $content, string $search, string $replace, string $label): string
{
    if (strpos($content, $replace) !== false) {
        inst_add('OK', 'Already patched: ' . $label);
        return $content;
    }

    $pos = strpos($content, $search);
    if ($pos === false) {
        inst_add('ERROR', 'Patch anchor not found: ' . $label);
        return $content;
    }

    inst_add('OK', 'Patched: ' . $label);
    return substr_replace($content, $replace, $pos, strlen($search));
}

function inst_patch_file(string $path, callable $patcher, string $stamp): void
{
    if (!is_file($path)) {
        inst_add('ERROR', 'Missing file: ' . basename($path));
        return;
    }

    $original = @file_get_contents($path);
    if ($original === false) {
        inst_add('ERROR', 'Could not read: ' . basename($path));
        return;
    }

    $patched = $patcher($original);
    if ($patched === $original) {
        inst_add('OK', 'No write needed: ' . basename($path));
        return;
    }

    if (!inst_backup($path, $stamp)) {
        inst_add('ERROR', 'Backup failed: ' . basename($path));
        return;
    }
    inst_add('OK', 'Backup created: ' . basename($path) . '.bak_' . $stamp);

    if (@file_put_contents($path, $patched, LOCK_EX) === false) {
        inst_add('ERROR', 'Write failed: ' . basename($path));
        return;
    }

    inst_add('OK', 'Updated: ' . basename($path));
}

$helperPath = $root . '/race_results_snapshot_views_helper.php';
if (is_file($helperPath)) {
    if (!inst_backup($helperPath, $stamp)) {
        inst_add('ERROR', 'Existing helper backup failed.');
    } else {
        inst_add('OK', 'Backup created: race_results_snapshot_views_helper.php.bak_' . $stamp);
    }
}
if (@file_put_contents($helperPath, $helperContent, LOCK_EX) === false) {
    inst_add('ERROR', 'Could not write race_results_snapshot_views_helper.php');
} else {
    inst_add('OK', 'Installed: race_results_snapshot_views_helper.php v001');
}

inst_patch_file(
    $root . '/race_results_monitor.php',
    function (string $c): string {
        $c = inst_replace_once(
            $c,
            " * VERSION: v137\n * LAST MODIFIED: 7/5/2026 12:47:15 am\n *\n * CHANGELOG:\n",
            " * VERSION: v138\n * LAST MODIFIED: 7/19/2026 1:35:18 pm\n *\n * CHANGELOG:\n *\n * v138 (7/19/2026 1:35:18 pm)\n *   - NEW: Every accepted canonical snapshot now generates matching _lite, _mrl, and _mrl_segment companions.\n *   - NEW: Uses race_results_snapshot_views_helper.php so initial and revision snapshots share one generation path.\n *   - CHANGE: Companion generation is logged separately and does not control release-history or classification decisions.\n",
            'race monitor header/changelog v138'
        );

        $c = str_replace(
            "const RR_MONITOR_SIGNATURE = 'RACE_RESULTS_MONITOR v136';",
            "const RR_MONITOR_SIGNATURE = 'RACE_RESULTS_MONITOR v138';",
            $c
        );

        $c = inst_replace_once(
            $c,
            "require_once __DIR__ . '/race_results_snapshot_helper.php';\n",
            "require_once __DIR__ . '/race_results_snapshot_helper.php';\nrequire_once __DIR__ . '/race_results_snapshot_views_helper.php';\n",
            'race monitor companion helper include'
        );

        $anchor = "    rr_save_snapshot_summary(\$raceFolder, \$tsFile, \$html2);\n";
        $insert = $anchor
            . "    if (\$snapshotPath !== '' && !\$isExh && \$raceNum !== null && (int)\$raceNum > 0 && function_exists('rrsv_generate_companion_set')) {\n"
            . "        \$companionSet = rrsv_generate_companion_set(\n"
            . "            \$snapshotPath,\n"
            . "            (int)\$year,\n"
            . "            (int)\$raceNum,\n"
            . "            (string)\$raceFolderName,\n"
            . "            \$dbo ?? null,\n"
            . "            \$dbconnect ?? null,\n"
            . "            true\n"
            . "        );\n"
            . "        rr_log_line(\n"
            . "            \$logFile,\n"
            . "            'SNAPSHOT COMPANIONS ' . (!empty(\$companionSet['ok']) ? 'OK' : 'ERROR')\n"
            . "            . ' canonical=' . basename(\$snapshotPath)\n"
            . "            . ' files=' . implode(',', array_values(\$companionSet['files'] ?? []))\n"
            . "            . ' errors=' . implode(' | ', \$companionSet['errors'] ?? [])\n"
            . "        );\n"
            . "    }\n";

        $c = inst_replace_once($c, $anchor, $insert, 'race monitor companion generation call');
        return $c;
    },
    $stamp
);

inst_patch_file(
    $root . '/race_results_revision_monitor.php',
    function (string $c): string {
        $c = inst_replace_once(
            $c,
            " * VERSION: v012\n * LAST MODIFIED: 7/5/2026 12:47:15 am\n *\n * CHANGELOG:\n",
            " * VERSION: v013\n * LAST MODIFIED: 7/19/2026 1:35:18 pm\n *\n * CHANGELOG:\n * v013 (7/19/2026 1:35:18 pm)\n *   - NEW: Every accepted revision snapshot now generates matching _lite, _mrl, and _mrl_segment companions.\n *   - NEW: Uses race_results_snapshot_views_helper.php so initial and revision snapshots share one generation path.\n *   - CHANGE: All four timestamp-matched files are always generated; classification decides what changed later.\n",
            'revision monitor header/changelog v013'
        );

        $c = str_replace(
            "const RR_REVISION_MONITOR_SIGNATURE = 'RACE_RESULTS_REVISION_MONITOR v011';",
            "const RR_REVISION_MONITOR_SIGNATURE = 'RACE_RESULTS_REVISION_MONITOR v013';",
            $c
        );

        $c = inst_replace_once(
            $c,
            "require_once __DIR__ . '/race_results_engine.php';\n",
            "require_once __DIR__ . '/race_results_engine.php';\nrequire_once __DIR__ . '/race_results_snapshot_views_helper.php';\n",
            'revision monitor companion helper include'
        );

        $anchor = "        rr_save_snapshot_html(\$raceFolder, \$tsFile, \$html, \$snapshotMaxBytes);\n        rr_save_snapshot_summary(\$raceFolder, \$tsFile, \$html);\n        \$currentSnapshotBase = 'snapshot_' . \$tsFile . '.html';\n";
        $insert = "        \$currentSnapshotPath = rr_save_snapshot_html(\$raceFolder, \$tsFile, \$html, \$snapshotMaxBytes);\n"
            . "        rr_save_snapshot_summary(\$raceFolder, \$tsFile, \$html);\n"
            . "        \$currentSnapshotBase = 'snapshot_' . \$tsFile . '.html';\n"
            . "        if (\$currentSnapshotPath !== '' && function_exists('rrsv_generate_companion_set')) {\n"
            . "            \$raceNumberForViews = (int)preg_replace('/\\D+/', '', (string)\$raceCode);\n"
            . "            \$companionSet = rrsv_generate_companion_set(\n"
            . "                \$currentSnapshotPath,\n"
            . "                (int)\$year,\n"
            . "                \$raceNumberForViews,\n"
            . "                (string)\$folderName,\n"
            . "                \$dbo ?? null,\n"
            . "                \$dbconnect ?? null,\n"
            . "                true\n"
            . "            );\n"
            . "            rr_log_line(\n"
            . "                \$logFile,\n"
            . "                'SNAPSHOT COMPANIONS ' . (!empty(\$companionSet['ok']) ? 'OK' : 'ERROR')\n"
            . "                . ' canonical=' . basename(\$currentSnapshotPath)\n"
            . "                . ' files=' . implode(',', array_values(\$companionSet['files'] ?? []))\n"
            . "                . ' errors=' . implode(' | ', \$companionSet['errors'] ?? [])\n"
            . "            );\n"
            . "        }\n";

        $c = inst_replace_once($c, $anchor, $insert, 'revision monitor companion generation call');
        return $c;
    },
    $stamp
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MRL Snapshot Companion Generation Installer</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{font-family:Arial,Helvetica,sans-serif;background:#f3f3f3;margin:24px;color:#111}
.wrap{max-width:980px;margin:auto;background:#fff;border:1px solid #bbb;border-radius:8px;padding:18px}
h1{margin:0 0 12px}.ok{color:#087b2e}.error{color:#c00000}
li{margin:6px 0;font-family:Consolas,monospace}
</style>
</head>
<body>
<div class="wrap">
<h1>MRL Snapshot Companion Generation Installer</h1>
<h2 class="<?= $failed ? 'error' : 'ok' ?>"><?= $failed ? 'COMPLETED WITH ERRORS' : 'SUCCESS — files installed and patched.' ?></h2>
<ul>
<?php foreach ($report as $row): ?>
<li class="<?= $row['status'] === 'ERROR' ? 'error' : 'ok' ?>">
<?= htmlspecialchars($row['status'] . ' — ' . $row['message'], ENT_QUOTES, 'UTF-8') ?>
</li>
<?php endforeach; ?>
</ul>
<p>After a successful install, open or lint both monitors, then delete this installer.</p>
</div>
</body>
</html>
