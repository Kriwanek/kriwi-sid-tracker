export const NOTE_NAMES = [
  "C",
  "C#",
  "D",
  "D#",
  "E",
  "F",
  "F#",
  "G",
  "G#",
  "A",
  "A#",
  "B",
];

export const DEFAULT_NOTES = ["C4", "D4", "E4", "G4", "A4"];

export function createDefaultSong() {
  return {
    tempo: 120,
    patterns: [createPattern(16)],
    instruments: [createInstrument()],
  };
}

export function createPattern(length) {
  return {
    length,
    steps: Array.from({ length }, (_, index) => ({
      index,
      note: DEFAULT_NOTES[index % DEFAULT_NOTES.length],
      active: index % 4 === 0,
    })),
  };
}

export function createInstrument() {
  return {
    name: "Pulse Lead",
    waveform: "pulse",
    adsr: {
      attack: 0.02,
      decay: 0.2,
      sustain: 0.7,
      release: 0.2,
    },
    volume: 0.6,
  };
}

export function noteNumberToName(midiNote) {
  const octave = Math.floor(midiNote / 12) - 1;
  const name = NOTE_NAMES[midiNote % 12];
  return `${name}${octave}`;
}

export function noteNameToFrequency(noteName) {
  const match = /([A-G]#?)(\d)/.exec(noteName);
  if (!match) {
    return 440;
  }
  const [, name, octaveStr] = match;
  const octave = Number(octaveStr);
  const noteIndex = NOTE_NAMES.indexOf(name);
  const midiNote = noteIndex + (octave + 1) * 12;
  return 440 * Math.pow(2, (midiNote - 69) / 12);
}
