# Updates

## 10.3.2 - 2026-04-28

- Reworked the mobile session layout to keep the header and footer anchored while the conversation area fills the available height and scrolls independently.
- Compacted the mobile voice controls into a single fixed row with simpler play, stop, and save icons.

## 10.3.1 - 2026-04-28

- Limited sidebar session history to authenticated users and synchronized saved sessions by WordPress account across devices.
- Kept anonymous session saves available for backend/admin review while hiding them from the frontend conversation list.

## 10.3.0 - 2026-03-21

- Fixed transcript auto-scroll behavior during long responses, stop/resume, and session view switches.
- Improved realtime voice session stability with better turn tuning, noise handling, and transcription controls.
- Added advanced voice agent settings for turn detection, eagerness, noise reduction, language hint, and transcription hint.
- Prevented transcription hints from leaking into visible user messages.
- Reduced repeated or looping assistant openings at the start of voice sessions.
