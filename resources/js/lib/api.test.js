import { afterEach, describe, expect, it } from 'vitest';
import { extractError, getApiLocale, setApiLocale } from './api';

const originalOnLine = Object.getOwnPropertyDescriptor(navigator, "onLine");

afterEach(() => {
  if (originalOnLine) {
    Object.defineProperty(navigator, "onLine", originalOnLine);
  } else {
    delete navigator.onLine;
  }
});

function setNavigatorOnline(value) {
  Object.defineProperty(navigator, "onLine", {
    configurable: true,
    value,
  });
}

describe('extractError', () => {
  it('returns top-level API message when available', () => {
    const error = {
      response: {
        data: {
          message: 'Invalid login credentials.',
        },
      },
    };

    expect(extractError(error)).toBe('Invalid login credentials.');
  });

  it('returns first validation error message', () => {
    const error = {
      response: {
        data: {
          errors: {
            email: ['The email field is required.'],
            password: ['The password field is required.'],
          },
        },
      },
    };

    expect(extractError(error)).toBe('The email field is required.');
  });

  it('returns caller fallback when payload does not include known keys', () => {
    expect(extractError({}, 'Request failed.')).toBe('Request failed.');
  });

  it("returns a friendly offline message for network failures", () => {
    setNavigatorOnline(false);

    expect(extractError({ request: {} }, "Request failed.")).toBe(
      "You appear to be offline. Reconnect and try again.",
    );
  });
});

describe("setApiLocale", () => {
  it("normalizes and stores the request locale", () => {
    const locale = setApiLocale("es-MX", {
      supportedLocales: ["en", "es"],
      fallbackLocale: "en",
    });

    expect(locale).toBe("es");
    expect(getApiLocale()).toBe("es");
  });
});
