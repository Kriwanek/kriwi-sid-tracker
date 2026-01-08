# Feature-Matrix und Roadmap

## Feature-Matrix

| FL Studio | Goat Tracker | Beschreibung / Ziel im SID-Tracker |
| --- | --- | --- |
| Mixer | — | Kanal-Levels, Mute/Solo, Master-Pegel, Metering |
| FX-Chain | — | Effektkette je Track (Filter, Delay, Drive) |
| Piano Roll | Pattern Editor | Noten- und Timing-Ansicht pro Pattern |
| Playlist | — | Song-Arranger mit Pattern-Blöcken |
| Step Sequencer | Pattern Editor | Step-basierte Eingabe für Drums/Arps |
| — | SID-Commands | SID-spezifische Kommandos (Filter, Pulse, Wave) |
| — | Instruments | Instrument-Definitionen inkl. ADSR und Wellenform |

## Phasen

### MVP
**Ziel:** Spielfähiger Prototyp mit Import, Editor und einfacher Wiedergabe.

**UI-Screens**
- **Projektstart / Workspace**: Titel, Tempo, Transport, Datei-Import.
- **Pattern-Editor**: 16-Step-Grid, Noten pro Step, aktive Steps.
- **Instrument-Panel**: Wellenform, ADSR, Lautstärke.

**Datenmodelle**
- `Song`: tempo, patterns[], instruments[]
- `Pattern`: length, steps[]
- `Step`: index, note, active
- `Instrument`: name, waveform, adsr, volume

### Phase 2
**Ziel:** Arranger, Mixer und erste SID-Kommandos.

**UI-Screens**
- **Playlist/Arranger**: Pattern in Timeline, Loop, Sections.
- **Mixer**: Track-Level, Mute/Solo, Master.
- **SID-Command-Panel**: Filter, Pulse Width, Arpeggio.

**Datenmodelle**
- `Track`: patterns[] with start/length
- `MixBus`: gain, mute, solo
- `SidCommand`: type, value, stepIndex

### Phase 3
**Ziel:** FX-Chain, erweiterte Editoren und Export.

**UI-Screens**
- **FX-Chain**: Effekte je Track, Reorder, Bypass.
- **Piano Roll Advanced**: längere Noten, Velocity, Automation.
- **Export/Render**: SID, WAV, Track stems.

**Datenmodelle**
- `Effect`: type, params
- `AutomationLane`: target, points[]
- `RenderJob`: format, options
