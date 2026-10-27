# Updates

## 10.3.5 - 2026-10-27

- Preserved interrupted assistant text in its original conversation position, including saved and reopened transcripts.
- Separated visible/archive history from resumable model context, marking interrupted or incomplete entries without deleting their received text.
- Kept regular streaming captions free of interruption labels and prevented late cancellation or item metadata from moving or replacing frozen text.
- Included interruption markers in post-session analysis while excluding unconfirmed speech from text-response context.

## 10.3.4 - 2026-10-27

- Reworked voice transcript handling around Realtime item IDs and authoritative completion events, preserving legitimate repeated speech.
- Added voice connection and playback lifecycle diagnostics, improved microphone cleanup, and prevented stale session events from affecting new conversations.
- Updated the default Realtime Mini model to `gpt-realtime-2.1-mini`; original Mini aliases and the retired October 2025 snapshot resolve to the new default while other configured models remain selectable.
- Added offline regression coverage for voice events and model selection.

## 10.3.3 - 2026-06-02

- Updated Realtime voice session initialization to use the current OpenAI client secrets and WebRTC calls endpoints.
- Preserved the existing tutor voice, transcription, noise reduction, and turn detection settings while migrating the request payload shape.

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
