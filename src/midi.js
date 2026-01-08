import { noteNumberToName } from "./models.js";

function readUInt32(view, offset) {
  return view.getUint32(offset);
}

function readUInt16(view, offset) {
  return view.getUint16(offset);
}

function readVarLength(view, offset) {
  let value = 0;
  let position = offset;
  while (true) {
    const byte = view.getUint8(position);
    value = (value << 7) + (byte & 0x7f);
    position += 1;
    if ((byte & 0x80) === 0) {
      break;
    }
  }
  return { value, nextOffset: position };
}

export function parseMidiFile(arrayBuffer) {
  const view = new DataView(arrayBuffer);
  const header = String.fromCharCode(
    view.getUint8(0),
    view.getUint8(1),
    view.getUint8(2),
    view.getUint8(3)
  );
  if (header !== "MThd") {
    throw new Error("Keine gültige MIDI-Datei.");
  }
  const headerLength = readUInt32(view, 4);
  const format = readUInt16(view, 8);
  const trackCount = readUInt16(view, 10);
  const division = readUInt16(view, 12);

  const tracks = [];
  let offset = 8 + headerLength;

  for (let trackIndex = 0; trackIndex < trackCount; trackIndex += 1) {
    const chunkType = String.fromCharCode(
      view.getUint8(offset),
      view.getUint8(offset + 1),
      view.getUint8(offset + 2),
      view.getUint8(offset + 3)
    );
    offset += 4;
    const chunkLength = readUInt32(view, offset);
    offset += 4;
    if (chunkType !== "MTrk") {
      offset += chunkLength;
      continue;
    }

    const trackEnd = offset + chunkLength;
    let ticks = 0;
    let runningStatus = null;
    const events = [];

    while (offset < trackEnd) {
      const delta = readVarLength(view, offset);
      ticks += delta.value;
      offset = delta.nextOffset;

      let status = view.getUint8(offset);
      if (status < 0x80 && runningStatus !== null) {
        status = runningStatus;
      } else {
        offset += 1;
        runningStatus = status;
      }

      if (status === 0xff) {
        const metaType = view.getUint8(offset);
        offset += 1;
        const lengthInfo = readVarLength(view, offset);
        offset = lengthInfo.nextOffset + lengthInfo.value;
        if (metaType === 0x2f) {
          break;
        }
        continue;
      }

      const eventType = status & 0xf0;
      if (eventType === 0x90 || eventType === 0x80) {
        const note = view.getUint8(offset);
        const velocity = view.getUint8(offset + 1);
        offset += 2;
        if (eventType === 0x90 && velocity > 0) {
          events.push({ ticks, note, velocity });
        }
        continue;
      }

      if (eventType === 0xc0 || eventType === 0xd0) {
        offset += 1;
        continue;
      }

      offset += 2;
    }

    tracks.push({ format, division, events });
  }

  return tracks;
}

export function midiToPattern(tracks, patternLength = 16) {
  if (!tracks.length) {
    return null;
  }
  const track = tracks[0];
  const ticksPerBeat = track.division || 480;
  const steps = Array.from({ length: patternLength }, (_, index) => ({
    index,
    note: "C4",
    active: false,
  }));

  track.events.forEach((event) => {
    const beatPosition = event.ticks / ticksPerBeat;
    const step = Math.floor(beatPosition * 4);
    if (step >= 0 && step < patternLength) {
      steps[step].active = true;
      steps[step].note = noteNumberToName(event.note);
    }
  });

  return {
    length: patternLength,
    steps,
  };
}
