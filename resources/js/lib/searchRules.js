// Shared rules for pickers that search a list stored in the database.
//
// Nothing is listed until MIN_SEARCH_CHARS characters are typed; below that the server only
// returns an exact name match, so a short name can still be found by typing it in full.
export const MIN_SEARCH_CHARS = 3;

// Wait this long after the last keystroke before searching.
export const SEARCH_DELAY_MS = 300;
