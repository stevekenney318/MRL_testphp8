<?php
/**
 * MRL Functions
 * 
 * @file    functions_mrl.php
 * @desc    Shared utility functions for the Manlius Racing League
 * @version v001
 * @updated 3/16/2026 5:47:12 am
 *
 * CHANGELOG:
 *
 * v001 (3/16/2026)
 *   - Initial file creation
 *   - Added disableCaching()
 *  
 */
// ---------------------------------------------------------------------
// Disable HTTP caching
// Dynamic responses must always be retrieved from the server
// ---------------------------------------------------------------------
function disableCaching(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Expires: 0');
}