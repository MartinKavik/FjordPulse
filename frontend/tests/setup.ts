import "@testing-library/jest-dom/vitest";

class ResizeObserverStub implements ResizeObserver {
  public disconnect(): void {}
  public observe(): void {}
  public unobserve(): void {}
}

Object.defineProperty(globalThis, "ResizeObserver", { value: ResizeObserverStub, configurable: true });
