import { noteNameToFrequency } from "./models.js";

export function createAudioEngine() {
  const context = new (window.AudioContext || window.webkitAudioContext)();
  let timer = null;
  let stepIndex = 0;

  function playStep(step, instrument, tempo) {
    if (!step.active) {
      return;
    }
    const now = context.currentTime;
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    const frequency = noteNameToFrequency(step.note);

    oscillator.type = instrument.waveform === "pulse" ? "square" : instrument.waveform;
    oscillator.frequency.setValueAtTime(frequency, now);

    const { attack, decay, sustain, release } = instrument.adsr;
    gain.gain.setValueAtTime(0, now);
    gain.gain.linearRampToValueAtTime(instrument.volume, now + attack);
    gain.gain.linearRampToValueAtTime(instrument.volume * sustain, now + attack + decay);
    gain.gain.linearRampToValueAtTime(0, now + attack + decay + release);

    oscillator.connect(gain).connect(context.destination);
    oscillator.start(now);
    oscillator.stop(now + attack + decay + release + 0.05);
  }

  function start(pattern, instrument, tempo, onStep) {
    if (timer) {
      return;
    }
    stepIndex = 0;
    const interval = (60 / tempo) / 4 * 1000;

    timer = setInterval(() => {
      const step = pattern.steps[stepIndex % pattern.length];
      playStep(step, instrument, tempo);
      if (onStep) {
        onStep(stepIndex % pattern.length);
      }
      stepIndex += 1;
    }, interval);
  }

  function stop() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
  }

  return {
    context,
    start,
    stop,
  };
}
