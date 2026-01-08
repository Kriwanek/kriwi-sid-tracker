import { createAudioEngine } from "./audio.js";
import { createDefaultSong } from "./models.js";
import { midiToPattern, parseMidiFile } from "./midi.js";

const state = {
  song: createDefaultSong(),
  playing: false,
  currentStep: 0,
};

const elements = {
  tempo: document.getElementById("tempo"),
  play: document.getElementById("play"),
  stop: document.getElementById("stop"),
  grid: document.getElementById("pattern-grid"),
  importStatus: document.getElementById("import-status"),
  midiFile: document.getElementById("midi-file"),
  instrumentName: document.getElementById("instrument-name"),
  instrumentWave: document.getElementById("instrument-wave"),
  attack: document.getElementById("attack"),
  decay: document.getElementById("decay"),
  sustain: document.getElementById("sustain"),
  release: document.getElementById("release"),
};

const audioEngine = createAudioEngine();

function renderPattern() {
  const pattern = state.song.patterns[0];
  elements.grid.innerHTML = "";

  pattern.steps.forEach((step) => {
    const card = document.createElement("div");
    card.className = `step ${step.active ? "active" : ""}`;
    const number = document.createElement("div");
    number.className = "step-number";
    number.textContent = `Step ${step.index + 1}`;

    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.checked = step.active;
    checkbox.addEventListener("change", () => {
      step.active = checkbox.checked;
      renderPattern();
    });

    const noteInput = document.createElement("input");
    noteInput.type = "text";
    noteInput.value = step.note;
    noteInput.addEventListener("input", () => {
      step.note = noteInput.value.toUpperCase();
    });

    card.append(number, checkbox, noteInput);
    elements.grid.append(card);
  });
}

function updateInstrument() {
  const instrument = state.song.instruments[0];
  instrument.name = elements.instrumentName.value;
  instrument.waveform = elements.instrumentWave.value;
  instrument.adsr.attack = Number(elements.attack.value);
  instrument.adsr.decay = Number(elements.decay.value);
  instrument.adsr.sustain = Number(elements.sustain.value);
  instrument.adsr.release = Number(elements.release.value);
}

function setPlaying(isPlaying) {
  state.playing = isPlaying;
  elements.play.disabled = isPlaying;
  elements.stop.disabled = !isPlaying;
}

function startPlayback() {
  updateInstrument();
  const pattern = state.song.patterns[0];
  const instrument = state.song.instruments[0];
  const tempo = Number(elements.tempo.value);

  audioEngine.start(pattern, instrument, tempo, (stepIndex) => {
    state.currentStep = stepIndex;
  });
  setPlaying(true);
}

function stopPlayback() {
  audioEngine.stop();
  setPlaying(false);
}

function handleMidiImport(file) {
  const reader = new FileReader();
  reader.onload = () => {
    try {
      const tracks = parseMidiFile(reader.result);
      const pattern = midiToPattern(tracks, 16);
      if (pattern) {
        state.song.patterns[0] = pattern;
        renderPattern();
        elements.importStatus.textContent = "MIDI importiert und Pattern aktualisiert.";
      } else {
        elements.importStatus.textContent = "Keine Events gefunden.";
      }
    } catch (error) {
      elements.importStatus.textContent = error.message;
    }
  };
  reader.readAsArrayBuffer(file);
}

function setupEvents() {
  elements.play.addEventListener("click", startPlayback);
  elements.stop.addEventListener("click", stopPlayback);
  elements.instrumentName.addEventListener("input", updateInstrument);
  elements.instrumentWave.addEventListener("change", updateInstrument);
  [elements.attack, elements.decay, elements.sustain, elements.release].forEach((input) => {
    input.addEventListener("input", updateInstrument);
  });
  elements.midiFile.addEventListener("change", (event) => {
    const file = event.target.files[0];
    if (file) {
      handleMidiImport(file);
    }
  });
}

renderPattern();
setupEvents();
