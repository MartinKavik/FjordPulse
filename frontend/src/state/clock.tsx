import { createContext, createSignal, onCleanup, onMount, useContext, type Accessor, type Component, type JSX } from "solid-js";

const ClockContext = createContext<Accessor<number>>(() => Date.now());

export const ClockProvider: Component<{
  readonly children: JSX.Element;
  readonly now?: Accessor<number>;
}> = (props) => {
  const [liveNow, setLiveNow] = createSignal(Date.now());

  onMount(() => {
    if (props.now !== undefined) return;
    const timer = window.setInterval(() => setLiveNow(Date.now()), 1_000);
    onCleanup(() => window.clearInterval(timer));
  });

  return <ClockContext.Provider value={props.now ?? liveNow}>{props.children}</ClockContext.Provider>;
};

export function useClock(): Accessor<number> {
  return useContext(ClockContext);
}
