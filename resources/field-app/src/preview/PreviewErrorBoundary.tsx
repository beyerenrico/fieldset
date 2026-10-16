import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
    /** A new key (e.g. the next definition) clears a previous error. */
    readonly resetKey: unknown;
    readonly fallback: ReactNode;
    readonly children: ReactNode;
}

interface State {
    readonly key: unknown;
    readonly failed: boolean;
}

/** Keeps the preview alive when an unsaved, half-edited definition cannot be rendered. */
export class PreviewErrorBoundary extends Component<Props, State> {
    override state: State = { key: this.props.resetKey, failed: false };

    static getDerivedStateFromProps(props: Props, state: State): State | null {
        return props.resetKey === state.key ? null : { key: props.resetKey, failed: false };
    }

    static getDerivedStateFromError(): Partial<State> {
        return { failed: true };
    }

    override componentDidCatch(error: Error, info: ErrorInfo): void {
        console.warn('Form preview could not render the definition', error, info.componentStack);
    }

    override render(): ReactNode {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}
