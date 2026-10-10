"use client";

import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent,
} from "react";
import { createPortal } from "react-dom";

export interface FinderSelectOption {
  value: string;
  label: string;
}

/**
 * A styled single-choice dropdown (button + listbox) for the ranking
 * finder: the open list matches the site instead of the operating system's
 * native menu. Keyboard: arrows, Home/End, Enter/Space, Escape and
 * type-ahead; a click outside closes it.
 */
export function FinderSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: FinderSelectOption[];
  onChange: (value: string) => void;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const button = useRef<HTMLButtonElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const typed = useRef({ text: "", at: 0 });
  const [place, setPlace] = useState<CSSProperties>({});
  const selected = Math.max(
    0,
    options.findIndex((o) => o.value === value),
  );

  // The list is rendered at the end of <body> so no card, hero or strip can clip or cover it.
  useLayoutEffect(() => {
    if (!open) return;
    const field =
      (root.current?.closest(".field") as HTMLElement | null) ?? root.current;
    const update = () => {
      const r = field?.getBoundingClientRect();
      if (r)
        setPlace({
          position: "fixed",
          top: r.bottom + 6,
          left: r.left,
          minWidth: r.width,
        });
    };
    update();
    window.addEventListener("resize", update);
    window.addEventListener("scroll", update, true);
    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("scroll", update, true);
    };
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => {
      const target = e.target as Node;
      if (!root.current?.contains(target) && !list.current?.contains(target))
        setOpen(false);
    };
    document.addEventListener("mousedown", close);
    list.current?.focus();
    return () => document.removeEventListener("mousedown", close);
  }, [open]);

  useEffect(() => {
    if (open)
      list.current
        ?.querySelector<HTMLElement>(`[data-index="${active}"]`)
        ?.scrollIntoView({ block: "nearest" });
  }, [open, active]);

  const show = () => {
    setActive(selected);
    setOpen(true);
  };
  const choose = (index: number) => {
    const option = options[index];
    if (option) onChange(option.value);
    setOpen(false);
    button.current?.focus();
  };
  const typeAhead = (key: string, now: number) => {
    typed.current = {
      text:
        (now - typed.current.at < 600 ? typed.current.text : "") +
        key.toLowerCase(),
      at: now,
    };
    const found = options.findIndex((o) =>
      o.label.toLowerCase().startsWith(typed.current.text),
    );
    if (found >= 0) setActive(found);
  };

  const onButtonKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    if (["ArrowDown", "ArrowUp", "Enter", " "].includes(e.key)) {
      e.preventDefault();
      show();
    }
  };
  const onListKey = (e: KeyboardEvent<HTMLUListElement>) => {
    const last = options.length - 1;
    if (e.key === "ArrowDown") setActive((i) => Math.min(last, i + 1));
    else if (e.key === "ArrowUp") setActive((i) => Math.max(0, i - 1));
    else if (e.key === "Home") setActive(0);
    else if (e.key === "End") setActive(last);
    else if (e.key === "Enter" || e.key === " ") choose(active);
    else if (e.key === "Escape") {
      setOpen(false);
      button.current?.focus();
    } else if (e.key === "Tab") {
      setOpen(false);
      return;
    } else if (e.key.length === 1) typeAhead(e.key, e.timeStamp);
    else return;
    e.preventDefault();
  };

  return (
    <div className={`fselect${open ? " fselect--open" : ""}`} ref={root}>
      <span className="fselect__label" id={`${id}-label`}>
        {label}
      </span>
      <button
        ref={button}
        type="button"
        className="fselect__button"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-labelledby={`${id}-label ${id}-value`}
        onClick={() => (open ? setOpen(false) : show())}
        onKeyDown={onButtonKey}
      >
        <span id={`${id}-value`} className="fselect__value">
          {options[selected]?.label ?? ""}
        </span>
        <svg
          className="fselect__chevron"
          viewBox="0 0 20 20"
          aria-hidden="true"
          focusable="false"
        >
          <path
            d="m5 7.5 5 5 5-5"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
          />
        </svg>
      </button>
      {open &&
        createPortal(
          <ul
            ref={list}
            style={place}
            className="fselect__list"
            role="listbox"
            tabIndex={-1}
            aria-labelledby={`${id}-label`}
            aria-activedescendant={`${id}-opt-${active}`}
            onKeyDown={onListKey}
          >
            {options.map((o, i) => (
              <li
                key={o.value}
                id={`${id}-opt-${i}`}
                data-index={i}
                role="option"
                aria-selected={i === selected}
                className={`fselect__option${i === active ? " is-active" : ""}${i === selected ? " is-selected" : ""}`}
                onMouseEnter={() => setActive(i)}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => choose(i)}
              >
                {o.label}
                {i === selected && (
                  <svg
                    className="fselect__check"
                    viewBox="0 0 20 20"
                    aria-hidden="true"
                    focusable="false"
                  >
                    <path
                      d="m5 10.5 3.2 3.2L15 7"
                      fill="none"
                      stroke="currentColor"
                      strokeWidth="2"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                    />
                  </svg>
                )}
              </li>
            ))}
          </ul>,
          document.body,
        )}
    </div>
  );
}
