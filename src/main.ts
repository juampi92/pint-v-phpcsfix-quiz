import { mount } from 'svelte';
import App from './App.svelte';
import './app.css';

const target = document.getElementById('app');

if (!target) {
  throw new Error('Could not find the app mount target.');
}

mount(App, {
  target,
});

