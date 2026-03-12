#!/usr/bin/env node
import { startServer } from '../src/index.js';
startServer().catch(err => {
  console.error('Fatal:', err.message);
  process.exit(1);
});
