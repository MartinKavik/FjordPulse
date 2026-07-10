import { render } from "solid-js/web";
import { App } from "./App";

const root = document.getElementById("root");
if (root === null) throw new Error("FjordPulse root element is missing");

render(() => <App />, root);
